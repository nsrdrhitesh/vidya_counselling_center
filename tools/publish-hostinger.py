"""Publish tested artifacts to a branch with no root Composer/build commands.

This branch is public source/dependencies only. Hosting must target a directory
outside public_html. Runtime data and production configuration never enter Git.
"""
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import zipfile

headers = []
def git(*args, cwd=None):
    try:
        return subprocess.check_output(['git', *headers, *args], cwd=cwd, text=True).strip()
    except subprocess.CalledProcessError:
        raise SystemExit('Git operation failed: ' + args[0]) from None

root = Path(__file__).resolve().parents[1]
if os.environ.get('GITHUB_ACTIONS') != 'true':
    raise SystemExit('Publish from the remote tested workflow only.')
authorization = base64.b64encode(('x-access-token:' + os.environ['GH_TOKEN']).encode()).decode()
headers.extend(['-c', 'http.https://github.com/.extraheader=AUTHORIZATION: basic ' + authorization])
sha = os.environ['GITHUB_SHA']
if git('rev-parse', 'origin/main') != sha:
    raise SystemExit('A newer main commit exists; refusing stale publication.')
baseline = json.loads((root / 'deploy/schema-baseline.json').read_text())
actual = {p.relative_to(root).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest()
          for p in sorted((root / 'database/migrations').glob('*.php'))}
if actual != baseline:
    raise SystemExit('Database migrations changed. Review and deploy the database upgrade before updating the schema baseline.')
schema = hashlib.sha256(json.dumps(baseline, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
candidate = Path(sys.argv[1]).resolve()
private_zip = next(candidate.rglob('private-app.zip'))
public_zip = next(candidate.rglob('public-only.zip'))
manifest = json.loads((private_zip.parent / 'manifest.json').read_text())
if manifest['commit'] != sha:
    raise SystemExit('Artifact source commit mismatch.')
for path in [private_zip, public_zip]:
    if hashlib.sha256(path.read_bytes()).hexdigest() != manifest['files'][path.name]:
        raise SystemExit('Artifact checksum mismatch.')
with tempfile.TemporaryDirectory() as temporary:
    work = Path(temporary) / 'deployment'
    remote = git('remote', 'get-url', 'origin')
    exists = bool(git('ls-remote', '--heads', 'origin', 'hostinger-deploy'))
    if exists:
        git('clone', '--single-branch', '--branch', 'hostinger-deploy', remote, str(work))
    else:
        work.mkdir()
        git('init', '--initial-branch=hostinger-deploy', cwd=work)
        git('remote', 'add', 'origin', remote, cwd=work)
    release = work / 'releases' / sha
    release.mkdir(parents=True, exist_ok=True)
    for archive, target in [(private_zip, release), (public_zip, release / 'public')]:
        with zipfile.ZipFile(archive) as z:
            for item in z.infolist():
                path = Path(item.filename)
                if path.is_absolute() or '..' in path.parts or any(p.startswith('.env') for p in path.parts):
                    raise SystemExit('Unsafe artifact path.')
            z.extractall(target)
    files = {p.relative_to(release).as_posix(): hashlib.sha256(p.read_bytes()).hexdigest()
             for p in sorted(release.rglob('*')) if p.is_file()}
    content = {'commit': sha, 'schema_fingerprint': schema, 'files': files}
    deployment_manifest = release / 'deployment-manifest.json'
    deployment_manifest.write_text(json.dumps(content, sort_keys=True, indent=2))
    previous = json.loads((work / 'current.json').read_text()).get('release') if (work / 'current.json').exists() else None
    retain = {sha, previous}
    for old in (work / 'releases').iterdir():
        if old.is_dir() and old.name not in retain:
            shutil.rmtree(old)
    (work / 'current.json').write_text(json.dumps({'release': sha, 'manifest_sha256': hashlib.sha256(deployment_manifest.read_bytes()).hexdigest()}, indent=2))
    (work / 'README.md').write_text('Generated tested deployment artifact. Host outside public_html. No secrets or production database. Edit source on main only.\n')
    git('config', 'user.name', 'github-actions[bot]', cwd=work)
    git('config', 'user.email', '41898282+github-actions[bot]@users.noreply.github.com', cwd=work)
    git('add', '-f', 'releases', 'current.json', 'README.md', cwd=work)
    git('commit', '-m', 'Deploy tested main ' + sha, cwd=work)
    git('push', 'origin', 'hostinger-deploy', cwd=work)
print('Published tested release ' + sha + '; schema fingerprint ' + schema)
