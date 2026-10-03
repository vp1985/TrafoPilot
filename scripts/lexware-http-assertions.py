"""Native Dolibarr rejection evidence for local authenticated HTTP checks."""
from html import unescape


def assert_csrf_rejection(status, body, token):
    text = unescape(body)
    missing = 'refused by CSRF protection in main.inc.php. Token not provided.'
    expired = (
        'Security token has expired, so action has been canceled. Please try again.',
        'Die Seite war zu lange inaktiv (Sicherheitstoken abgelaufen). Bitte führen Sie die Aktion erneut aus.',
    )
    if status not in (200, 403):
        raise RuntimeError('UNEXPECTED_CSRF_HTTP_STATUS')
    expected = missing in text if token is None else any(message in text for message in expired)
    if not expected:
        raise RuntimeError('NATIVE_CSRF_REJECTION_NOT_CONFIRMED')
