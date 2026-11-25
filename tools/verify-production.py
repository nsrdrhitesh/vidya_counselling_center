"""Read-only production checks after the Hostinger Git webhook deploys."""
import os
import re
import time
import urllib.error
import urllib.request

root = 'https://vidyacounselling.com'
expected = os.environ['EXPECTED_RELEASE']
for attempt in range(30):
    try:
        response = urllib.request.urlopen(root + '/?deployment_check=' + expected, timeout=60)
        body = response.read().decode('utf-8')
        if response.status == 200 and response.headers.get('X-Vidya-Release') == expected:
            break
    except (urllib.error.URLError, TimeoutError):
        pass
    time.sleep(10)
else:
    raise SystemExit('Hostinger did not serve the expected source release. Check Advanced > Git branch/directory and deployment logs.')
for path, status in [('/login',200),('/admin',200),('/register',404),('/.env',403),('/.git/config',403),('/_deployment/current.json',403),('/composer.json',404),('/vendor/autoload.php',404)]:
    try:
        response = urllib.request.urlopen(root+path, timeout=30)
        actual = response.status
        if path == '/admin' and response.url != root+'/login':
            raise SystemExit('Admin is not protected by login.')
    except urllib.error.HTTPError as error:
        actual = error.code
    if actual != status:
        raise SystemExit('Unexpected HTTP status for '+path+': '+str(actual))
assets = set(re.findall(r'(?:href|src)=[\'\"]([^\'\"]+/_assets/[^\'\"]+)', body))
if not assets:
    raise SystemExit('Versioned assets missing from Laravel response.')
for asset in assets:
    response = urllib.request.urlopen(asset, timeout=30)
    if response.status != 200:
        raise SystemExit('Production asset failed.')
print('Production verified: '+expected+'; Laravel page, login protection, assets and secret-file exclusions passed.')
