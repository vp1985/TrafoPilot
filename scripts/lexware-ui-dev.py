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
import re
from contextlib import ExitStack

parser=argparse.ArgumentParser()
parser.add_argument('--visual', action='store_true')
parser.add_argument('--module-url', default='/custom/hwoslexware', help='DEV module URL to test')
parser.add_argument('--artifacts', default='/tmp/hwos-lexware-visual')
args=parser.parse_args()
if not args.module_url.startswith('/') or '..' in args.module_url or '?' in args.module_url or '#' in args.module_url:
    sys.exit('INVALID_MODULE_URL')
module_url=args.module_url.rstrip('/')
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
assert_csrf_rejection=runpy.run_path(str(Path(__file__).with_name('lexware-http-assertions.py')))['assert_csrf_rejection']
with state_stack:
    state_stack.enter_context(lock_api['invocation_lock']('/tmp/hwos-lexware-ui-'+str(os.getuid())+'.lock'))
    custom_mode=subprocess.check_output(['sudo','-n','docker','exec',container,'stat','-c','%a','/var/www/html/custom'],text=True).strip()
    login_name='lx-http-fixture-'+secrets.token_hex(8)
    password=secrets.token_urlsafe(32)
    root=Path(__file__).resolve().parents[1]
    helper_directory=None
    helper_path=None
    creation_attempted=False
    def lifecycle(data):
        result=subprocess.run(['sudo','-n','docker','exec','-i','-e','HWOS_MODULE_ROOT=/var/www/html'+module_url.rsplit('/',1)[0],'-e','HWOS_FIXTURE_RECOVERY='+helper_directory+'/recovery.json',container,'php',helper_path],input=json.dumps(data),text=True,capture_output=True)
        if data.get('action')=='cleanup':
            try:
                report=json.loads(result.stdout)
            except (ValueError, TypeError):
                report={}
            allowed_phases=('csrf','history','user','lexware','core','helper','baseline')
            attempted=[p for p in report.get('attempted',[]) if p in allowed_phases]
            failed=[f.split(':',1)[0] for f in report.get('failures',[]) if isinstance(f,str) and f.split(':',1)[0] in allowed_phases]
            if attempted: print('DEV_RECOVERY_PHASES='+','.join(attempted))
            if failed: print('DEV_RECOVERY_FAILED_PHASES='+','.join(failed))
        if result.returncode:
            marker=re.search(r'Uncaught (?:\w+): ([A-Z_]+)(?:\s|$)', result.stderr)
            if marker: print('FIXTURE_ERROR='+marker.group(1))
            locations=re.findall(r'fixture.php\((\d+)\)', result.stderr)
            if locations: print('FIXTURE_ERROR_LINE='+','.join(locations))
            failures=[marker for marker in ('UI_FIXTURE_INCOMPLETE_RECOVERY','UI_FIXTURE_CLEANUP_FAILED','UI_FIXTURE_RESTORE_FAILED','UNVERIFIED_BASELINE') if marker in result.stderr]
            raise RuntimeError('UI_FIXTURE_LIFECYCLE_FAILED'+(': '+'; '.join(failures) if failures else ''))
        return json.loads(result.stdout)
    try:
        helper_directory=subprocess.check_output(['sudo','-n','docker','exec',container,'mktemp','-d','/tmp/hwos-lexware-ui-XXXXXXXXXX'],text=True).strip()
        helper_path=helper_directory+'/fixture.php'
        subprocess.run(['sudo','-n','docker','cp',str(root/'scripts/lexware-ui-fixture.php'),container+':'+helper_path],check=True)
        # Support older DEV directory ownership; always restore the original mode below.
        subprocess.run(['sudo','-n','docker','exec',container,'chmod','o+x','/var/www/html/custom'],check=True)
        stage='fixture'
        # A failed create may have published its private journal before mutating.
        # Ask PHP to verify recovery state; never infer a prior module state here.
        creation_attempted=True
        fixture=lifecycle({'action':'create','login':login_name,'password':password})
        print('DEV_HTTP_FIXTURE_RIGHT='+str(fixture['permission_read'])+' MODULE='+str(fixture['module_enabled']))
        fixture.update(lifecycle({'action':'populate','login':login_name}))
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
            with browser.open(base+module_url+'/'+path,timeout=30) as response:
                html=response.read().decode()
                if title not in html or 'name="password"' in html:
                    sys.exit('DEV_HTTP_PAGE_CHECK_FAILED_'+path)
                print('PASS: authenticated DEV HTTP '+path)
        # This non-admin initially has every module right, including sync/admin.
        stage='non-admin-sync-rejection'
        url=base+module_url+'/index.php'
        with browser.open(url,timeout=30) as response: html=response.read().decode()
        if 'value="dry"' in html or 'value="full"' in html:
            sys.exit('DEV_NON_ADMIN_SYNC_CONTROLS_VISIBLE')
        fields=Inputs(); fields.feed(html); fields.values['action']='dry'
        request=urllib.request.Request(url,data=urllib.parse.urlencode(fields.values).encode(),headers={'Referer':url},method='POST')
        with browser.open(request,timeout=30) as response: denied=response.read().decode()
        if 'LEXWARE_PERMISSION_DENIED' not in denied:
            sys.exit('DEV_NON_ADMIN_SYNC_RIGHT_NOT_REJECTED')
        print('PASS: authenticated non-admin with sync/admin module rights cannot start sync')
        stage='csrf-global-disabled'
        csrf_before=lifecycle({'action':'state'})
        for page,fields in [('index.php',{'action':'retry','run':str(fixture['fixture_run'])}),('issues.php',{'action':'resolve','resource':str(fixture['resource_id']),'choice':'Dolibarr','checksum':fixture['source_checksum']}),('resource.php',{'action':'resolve','id':str(fixture['resource_id']),'choice':'Dolibarr','checksum':fixture['source_checksum']})]:
            url=base+module_url+'/'+page
            if page=='resource.php': url+='?id='+str(fixture['resource_id'])
            for token in (None,'forgedtoken'):
                posted=dict(fields)
                if token: posted['token']=token
                request=urllib.request.Request(url,data=urllib.parse.urlencode(posted).encode(),headers={'Referer':url},method='POST')
                try:
                    with browser.open(request,timeout=30) as response:
                        assert_csrf_rejection(response.status, response.read().decode(), token)
                except urllib.error.HTTPError as error:
                    assert_csrf_rejection(error.code, error.read().decode(), token)
            print('PASS: '+page+' rejects missing/forged token with global CSRF disabled')
        if csrf_before!=lifecycle({'action':'state'}): raise RuntimeError('CSRF_REJECTION_CHANGED_STATE')
        with browser.open(base+module_url+'/file.php?version=1&id='+str(fixture['historical_file_id']),timeout=30) as response:
            if response.read()!=b'%PDF-historical-file': raise RuntimeError('HISTORICAL_DOWNLOAD_BYTES_CHANGED')
        print('PASS: historical file download returns retained original bytes')
        for page in ('issues.php', 'resource.php?id='+str(fixture['resource_id'])):
            with browser.open(base+module_url+'/'+page,timeout=30) as response:
                mapping_html=response.read().decode()
            for indicator in ('value="Lexware"', 'value="Dolibarr"', 'name="checksum"', 'name="issue"', 'method="POST"', 'name="token"'):
                if indicator not in mapping_html: raise RuntimeError('MAPPING_RESOLUTION_FORM_MISSING')
        print('PASS: delegated mapping user sees protected resolution forms on issues/resource')
        stage='immutable-case-binding'
        def case_state(action='case-state'):
            return lifecycle({'action':action,'login':login_name,'resource_id':fixture['resource_id']})
        def render_case(page):
            url=base+module_url+'/'+page
            with browser.open(url,timeout=30) as response: html=response.read().decode()
            fields=Inputs(); fields.feed(html)
            if not fields.values.get('issue'): raise RuntimeError('ISSUE_ID_FORM_MISSING')
            return url,dict(fields.values)
        def submit_case(url,fields,choice):
            fields=dict(fields); fields.update({'action':'resolve','choice':choice})
            request=urllib.request.Request(url,data=urllib.parse.urlencode(fields).encode(),headers={'Referer':url},method='POST')
            with browser.open(request,timeout=30) as response: return response.read().decode()
        for choice,page in [('Lexware','resource.php?id='+str(fixture['resource_id'])),('Dolibarr','issues.php')]:
            url,old=render_case(page)
            if 'CONFLICT_CASE_CHANGED' in submit_case(url,old,'Lexware'): raise RuntimeError('CURRENT_CASE_A_REJECTED')
            case_state('case-next')
            before=case_state()
            # Refresh only CSRF token; keep A's immutable intent and checksum.
            _,current=render_case(page)
            if current['issue']==old['issue'] or current['checksum']!=old['checksum']: raise RuntimeError('CASE_B_FIXTURE_IDENTITY_FAILED')
            old['token']=current['token']
            rejected=submit_case(url,old,choice)
            if 'CONFLICT_CASE_CHANGED' not in rejected or case_state()!=before: raise RuntimeError('STALE_CASE_CHANGED_STATE_'+choice)
            _,current=render_case(page)
            submit_case(url,current,choice)
            after=case_state()
            if len(after[4])!=len(before[4])+1 or any(i['status']=='open' and i['issue_type']=='conflict' for i in after[3]): raise RuntimeError('EXPLICIT_CASE_B_FAILED_'+choice)
            print('PASS: HTTP stale case A rejected without native/mapping/B/history/audit changes; explicit B resolves '+choice)
            case_state('case-next')
        stage='permission-rejection'
        baseline=lifecycle({'action':'restrict','login':login_name,'id':fixture['id'],'read':True})
        for page in ('resource.php?id='+str(fixture['resource_id']), 'object.php?type=product&id='+str(fixture['native_id'])):
            with browser.open(base+module_url+'/'+page,timeout=30) as response: reader_html=response.read().decode()
            if 'role="alert"' not in reader_html or 'Projektion: conflict' not in reader_html or 'value="Lexware"' in reader_html:
                raise RuntimeError('READER_CONFLICT_VISIBILITY_FAILED')
        print('PASS: reader sees explicit conflict in resource/native tab without resolution controls')
        with browser.open(base+module_url+'/payload.php?version=1&id='+str(fixture['historical_payload_id']),timeout=30) as response:
            if response.read()!=fixture['historical_payload'].encode(): raise RuntimeError('HISTORICAL_PAYLOAD_BYTES_CHANGED')
        print('PASS: reader historical payload download returns exact original bytes')
        if args.visual:
            stage='visual-browser'
            visual=subprocess.run(['node',str(root/'scripts/lexware-visual-dev.cjs')],input=json.dumps({'login':login_name,'password':password,'artifacts':args.artifacts,'moduleUrl':module_url,'resourceId':fixture['resource_id'],'nativeId':fixture['native_id'],'readOnly':True}),text=True)
            if visual.returncode:
                raise RuntimeError('DEV_VISUAL_CHECK_FAILED')
        actions=[('index.php','dry',{}),('index.php','test',{}),('index.php','retry',{'run':'0'}),('issues.php','resolve',{'resource':'0','choice':'Lexware','checksum':'0'})]
        if baseline['resource_id']:
            actions.extend([('resource.php','refresh',{'id':str(baseline['resource_id'])}),('resource.php','map',{'id':str(baseline['resource_id']),'confirm':'yes','object':'1'})])
        for page,action,extra in actions:
            url=base+module_url+'/'+page
            if extra.get('id'): url+='?id='+extra['id']
            with browser.open(url,timeout=30) as response: html=response.read().decode()
            fields=Inputs(); fields.feed(html)
            if 'token' not in fields.values:
                with browser.open(base+module_url+'/index.php',timeout=30) as response:
                    token_fields=Inputs(); token_fields.feed(response.read().decode())
                fields.values['token']=token_fields.values.get('token','')
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
        with browser.open(urllib.request.Request(base+module_url+'/index.php',data=b'action=dry',headers={'Referer':base+module_url+'/index.php'},method='POST'),timeout=30) as response:
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
        recovery_failures=[]
        fixture_failed=False
        try:
            if creation_attempted:
                lifecycle({'action':'cleanup','login':login_name,**({k: fixture[k] for k in ('baseline_version','baseline_entity','active','active_rows','prior_csrf','id','fixture_run') if k in fixture} if fixture else {})})
        except Exception:
            fixture_failed=True
            recovery_failures.append('fixture')
        try:
            subprocess.run(['sudo','-n','docker','exec',container,'chmod',custom_mode,'/var/www/html/custom'],check=True)
        except Exception:
            recovery_failures.append('custom-mode')
        try:
            if helper_directory:
                if fixture_failed:
                    # Keep private recovery metadata available after an actual
                    # recovery error; remove the executable helper independently.
                    subprocess.run(['sudo','-n','docker','exec',container,'rm','-f','--',helper_path],check=True)
                    print('DEV_RECOVERY_DIRECTORY='+helper_directory)
                    if fixture and fixture.get('baseline_version') == 1:
                        print('DEV_RECOVERY_METADATA='+helper_directory+'/recovery.json')
                else:
                    subprocess.run(['sudo','-n','docker','exec',container,'rm','-rf','--',helper_directory],check=True)
        except Exception:
            recovery_failures.append('helper')
        try:
            state_stack.close()
        except Exception:
            recovery_failures.append('lock')
        if recovery_failures:
            raise RuntimeError('UI_FIXTURE_INCOMPLETE_RECOVERY: '+'; '.join(recovery_failures))
