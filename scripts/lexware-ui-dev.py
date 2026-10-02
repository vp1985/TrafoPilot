#!/usr/bin/env python3
"""Authenticated DEV HTTP checks. Do not emit passwords, cookies or HTML payloads."""
import http.cookiejar
from html.parser import HTMLParser
import subprocess
import urllib.parse
import urllib.request
import sys
import json
import secrets
import argparse
from pathlib import Path
import os
import runpy
from contextlib import ExitStack

parser=argparse.ArgumentParser()
parser.add_argument('--visual', action='store_true')
parser.add_argument('--artifacts', default='/tmp/hwos-lexware-visual')
args=parser.parse_args()
container='dolibarr-dev-dolibarr-1'
labels=subprocess.check_output(['sudo','-n','docker','inspect','--format','{{ index .Config.Labels "com.docker.compose.project" }}|{{ index .Config.Labels "com.docker.compose.service" }}',container],text=True).strip()
if labels!='dolibarr-dev|dolibarr':
    sys.exit('REFUSING_NON_DEV_TARGET')
class Inputs(HTMLParser):
    def __init__(self):
        super().__init__(); self.values={}
    def handle_starttag(self,tag,attrs):
        data=dict(attrs)
        if tag=='input' and data.get('type')=='hidden' and data.get('name'):
            self.values[data['name']]=data.get('value','')
base='http://127.0.0.1:8081'
jar=http.cookiejar.CookieJar()
browser=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
fixture=None
state_stack=ExitStack()
lock_api=runpy.run_path(str(Path(__file__).with_name('lexware-ui-lock.py')))
with state_stack:
    state_stack.enter_context(lock_api['invocation_lock']('/tmp/hwos-lexware-ui-'+str(os.getuid())+'.lock'))
    custom_mode=subprocess.check_output(['sudo','-n','docker','exec',container,'stat','-c','%a','/var/www/html/custom'],text=True).strip()
    login_name='lx-http-fixture-'+secrets.token_hex(8)
    password=secrets.token_urlsafe(32)
    root=Path(__file__).resolve().parents[1]
    helper_directory=None
    helper_path=None
    def lifecycle(data):
        result=subprocess.run(['sudo','-n','docker','exec','-i',container,'php',helper_path],input=json.dumps(data),text=True,capture_output=True)
        if result.returncode:
            failures=[marker for marker in ('UI_FIXTURE_INCOMPLETE_RECOVERY','UI_FIXTURE_CLEANUP_FAILED','UI_FIXTURE_RESTORE_FAILED') if marker in result.stderr]
            raise RuntimeError('UI_FIXTURE_LIFECYCLE_FAILED'+(': '+'; '.join(failures) if failures else ''))
        return json.loads(result.stdout)
    try:
        helper_directory=subprocess.check_output(['sudo','-n','docker','exec',container,'mktemp','-d','/tmp/hwos-lexware-ui-XXXXXXXXXX'],text=True).strip()
        helper_path=helper_directory+'/fixture.php'
        subprocess.run(['sudo','-n','docker','cp',str(root/'scripts/lexware-ui-fixture.php'),container+':'+helper_path],check=True)
        # Support older DEV directory ownership; always restore the original mode below.
        subprocess.run(['sudo','-n','docker','exec',container,'chmod','o+x','/var/www/html/custom'],check=True)
        stage='fixture'
        fixture=lifecycle({'action':'create','login':login_name,'password':password})
        print('DEV_HTTP_FIXTURE_RIGHT='+str(fixture['permission_read'])+' MODULE='+str(fixture['module_enabled']))
        stage='login-form'
        with browser.open(base+'/index.php',timeout=20) as r: login=r.read().decode()
        fields=Inputs(); fields.feed(login)
        fields.values.update({'username':login_name,'password':password,'actionlogin':'login','loginfunction':'loginfunction'})
        stage='login-post'
        request=urllib.request.Request(base+'/index.php',data=urllib.parse.urlencode(fields.values).encode(),headers={'Referer':base+'/index.php'},method='POST')
        with browser.open(request,timeout=20) as r: home=r.read().decode()
        if 'name="password"' in home:
            sys.exit('DEV_LOGIN_FAILED')
        for path,title in [('index.php','TrafoPilot – Lexware Office'),('resources.php','TrafoPilot – Lexware-Spiegel'),('issues.php','TrafoPilot – Lexware-Konflikte')]:
            stage=path
            with browser.open(base+'/custom/hwoslexware/'+path,timeout=30) as response:
                html=response.read().decode()
                if title not in html or 'name="password"' in html:
                    sys.exit('DEV_HTTP_PAGE_CHECK_FAILED_'+path)
                print('PASS: authenticated DEV HTTP '+path)
        if args.visual:
            stage='visual-browser'
            visual=subprocess.run(['node',str(root/'scripts/lexware-visual-dev.cjs')],input=json.dumps({'login':login_name,'password':password,'artifacts':args.artifacts}),text=True)
            if visual.returncode:
                raise RuntimeError('DEV_VISUAL_CHECK_FAILED')
        stage='permission-rejection'
        baseline=lifecycle({'action':'restrict','login':login_name,'id':fixture['id'],'read':True})
        actions=[('index.php','dry',{}),('index.php','test',{}),('index.php','retry',{'run':'0'})]
        if baseline['resource_id']:
            actions.extend([('resource.php','refresh',{'id':str(baseline['resource_id'])}),('resource.php','map',{'id':str(baseline['resource_id']),'confirm':'yes','object':'1'})])
        for page,action,extra in actions:
            url=base+'/custom/hwoslexware/'+page
            if extra.get('id'): url+='?id='+extra['id']
            with browser.open(url,timeout=30) as response: html=response.read().decode()
            fields=Inputs(); fields.feed(html)
            fields.values.update(extra); fields.values['action']=action
            request=urllib.request.Request(url,data=urllib.parse.urlencode(fields.values).encode(),headers={'Referer':url},method='POST')
            with browser.open(request,timeout=30) as response: denied=response.read().decode()
            if 'LEXWARE_PERMISSION_DENIED' not in denied:
                sys.exit('DEV_PERMISSION_REJECTION_NOT_CONFIRMED_'+action)
            print('PASS: DEV HTTP denies read-only user action '+action)
        after=lifecycle({'action':'restrict','login':login_name,'id':fixture['id'],'read':True})
        if baseline['checksums']!=after['checksums']:
            sys.exit('DEV_UNAUTHORIZED_HTTP_CHANGED_MIRROR_STATE')
        print('PASS: unauthorized HTTP actions preserve runs, resources and mappings')
        # A POST without a CSRF token must not start a synchronization run.
        stage='csrf-rejection'
        with browser.open(urllib.request.Request(base+'/custom/hwoslexware/index.php',data=b'action=dry',headers={'Referer':base+'/custom/hwoslexware/index.php'},method='POST'),timeout=30) as response:
            denied=response.read().decode()
            if 'CSRF' not in denied and 'token' not in denied.lower():
                sys.exit('DEV_CSRF_REJECTION_NOT_CONFIRMED')
        print('PASS: DEV HTTP rejects a POST without CSRF token')
    except urllib.error.HTTPError as e:
        if stage=='csrf-rejection' and e.code==403:
            print('PASS: DEV HTTP rejects a POST without CSRF token')
        else:
            reason=e.read().decode(errors='replace')
            for marker in ['You don\'t have permission','Access to this page forbidden','Module not enabled','CSRF','Forbidden','Bad permissions','Permission denied']:
                if marker in reason: print('DEV_HTTP_REASON='+marker)
            print('DEV_HTTP_ERROR_'+str(e.code)+'_AT_'+stage); sys.exit(1)
    except Exception as e:
        print('DEV_HTTP_CHECK_FAILED_'+type(e).__name__); sys.exit(1)
    finally:
        try:
            if fixture:
                lifecycle({'action':'cleanup','login':login_name,**fixture})
        finally:
            try:
                subprocess.run(['sudo','-n','docker','exec',container,'chmod',custom_mode,'/var/www/html/custom'],check=True)
            finally:
                try:
                    if helper_directory:
                        subprocess.run(['sudo','-n','docker','exec',container,'rm','-rf','--',helper_directory],check=True)
                finally:
                    state_stack.close()
