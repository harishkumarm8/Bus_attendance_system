"""Integration smoke tests against an isolated, empty local MySQL database.

No student or faculty fixtures are inserted. The temporary admin and database
are removed after the test. Run: python tests/verify.py
"""
import http.cookiejar
import pathlib
import re
import secrets
import shutil
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = pathlib.Path(r'C:\xampp\php\php.exe')
MYSQL = pathlib.Path(r'C:\xampp\mysql\bin\mysql.exe')
DB = 'bus_attendance_verify_' + secrets.token_hex(6)
PORT = 8094
BASE = f'http://127.0.0.1:{PORT}/'
TOKEN = secrets.token_hex(32)
PASSWORD = secrets.token_urlsafe(24)
checks = 0


def sql(statement):
    return subprocess.run([str(MYSQL), '--host=127.0.0.1', '--user=root', '--batch', '--skip-column-names'], input=statement, text=True, capture_output=True, check=True).stdout


def expect(condition, description):
    global checks
    if not condition:
        raise AssertionError(description)
    checks += 1
    print('PASS:', description)


jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def request(path, data=None):
    encoded = urllib.parse.urlencode(data).encode() if data is not None else None
    try:
        response = client.open(BASE + path, data=encoded, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode('utf-8-sig'), response.geturl(), response.headers


def csrf(body):
    return re.search(r'name="csrf" value="([a-f0-9]+)"', body).group(1)


server = None
try:
    sql((ROOT / 'database/bus_attendance.sql').read_text().replace('bus_attendance', DB))
    with tempfile.TemporaryDirectory(prefix='bus-attendance-verify-') as temp:
        temp = pathlib.Path(temp)
        for name in ['config.php', 'login.php', 'admin.php', 'staff.php']:
            content = (ROOT / name).read_text(encoding='utf-8')
            if name == 'config.php':
                content = content.replace("'name'=>'bus_attendance'", f"'name'=>'{DB}'").replace("const SETUP_TOKEN = '';", f"const SETUP_TOKEN = '{TOKEN}';")
            (temp / name).write_text(content, encoding='utf-8')
        shutil.copytree(ROOT / 'css', temp / 'css')
        shutil.copytree(ROOT / 'js', temp / 'js')
        with (temp / 'server.log').open('w') as log:
            server = subprocess.Popen([str(PHP), '-S', f'127.0.0.1:{PORT}', '-t', str(temp)], stdout=log, stderr=log)
            for _ in range(30):
                try:
                    status, body, url, headers = request('login.php')
                    break
                except OSError:
                    time.sleep(.2)
            expect(status == 200 and 'Sign in to your workspace' in body, 'Login renders against local MySQL')
            expect(headers.get('X-Frame-Options') == 'DENY', 'Security response headers')
            expect('HttpOnly' in headers.get('Set-Cookie', ''), 'Session cookie is HttpOnly')
            expect(request('admin.php')[2].endswith('login.php'), 'Anonymous admin access redirects')
            expect(request('staff.php')[2].endswith('login.php'), 'Anonymous staff access redirects')
            expect(request('login.php?action=logout')[0] == 405, 'GET logout rejected')
            expect(request('login.php', {'username':'test','password':PASSWORD,'role':'admin'})[0] == 403, 'Missing CSRF rejected')
            body = request('login.php?action=setup')[1]
            result = request('login.php?action=setup', {'csrf':csrf(body),'username':'verification_admin','password':PASSWORD,'setup_token':TOKEN})
            expect('Admin created.' in result[1], 'Initial admin setup works')
            stored = sql(f'SELECT password_hash FROM {DB}.admins;').strip()
            expect(stored.startswith('$2y$') and stored != PASSWORD, 'Admin password is hashed')
            before = next(cookie.value for cookie in jar if cookie.name == 'bus_attendance_session')
            result = request('login.php', {'csrf':csrf(result[1]),'username':'verification_admin','password':PASSWORD,'role':'admin'})
            after = next(cookie.value for cookie in jar if cookie.name == 'bus_attendance_session')
            expect(result[2].endswith('admin.php') and 'Transport overview' in result[1], 'Admin login and dashboard')
            expect(before != after, 'Session ID regenerated after login')
            expect(request('staff.php')[0] == 403, 'Admin cannot enter staff dashboard')
            expect('Your directory starts here' in request('admin.php?action=students')[1], 'Empty directory guidance')
            expect('No matching students' in request('admin.php?action=students&q=%3Cscript%3E')[1], 'Search and escaped filter')
            expect('No attendance records' in request('admin.php?action=reports')[1], 'Empty date report')
            exported = request('admin.php?action=export')
            expect('text/csv' in exported[3].get('Content-Type','') and 'Faculty ID' in exported[1], 'Authorized CSV export')
            expect('Choose a valid date' in request('admin.php?action=reports&date=2025-02-30')[1], 'Invalid calendar date rejected')
            expect(request('admin.php?action=update_student')[0] == 405, 'GET mutation rejected')
            token = csrf(request('admin.php')[1])
            result = request('admin.php?action=sync_students', {'csrf':token})
            expect('Cloud credentials are not configured' in result[1], 'Cloud outage/configuration fails clearly')
            expect(sql(f'SELECT COUNT(*) FROM {DB}.students;').strip() == '0', 'Failed sync inserts no students')
            expect(request('admin.php?action=unknown')[0] == 404, 'Unknown dashboard action returns 404')
            result = request('login.php?action=logout', {'csrf':token})
            expect(result[2].endswith('login.php'), 'POST logout succeeds')
            expect(request('admin.php?action=export')[2].endswith('login.php'), 'CSV denied after logout')
            expect(request('login.php?action=setup', {'csrf':csrf(result[1]), 'username':'second','password':PASSWORD,'setup_token':TOKEN})[1].find('Admin setup is unavailable') >= 0, 'Initial setup cannot be reused')
            for _ in range(11):
                body = request('login.php')[1]
                result = request('login.php', {'csrf':csrf(body),'username':'verification_admin','password':'incorrect','role':'admin'})
            expect('Too many login attempts' in result[1], 'Database-backed login rate limit')
            cols = sql(f'SHOW COLUMNS FROM {DB}.students;').strip().splitlines()
            expect(len(cols) == 9, 'Students schema has exactly nine columns')
            index = sql(f'SHOW INDEX FROM {DB}.attendance;')
            expect('uq_student_date' in index, 'Unique student/date key exists')
            server.terminate()
            server.wait(timeout=10)
            server = None
        expect('Fatal error' not in (temp / 'server.log').read_text() and 'Warning:' not in (temp / 'server.log').read_text(), 'No PHP runtime warnings during smoke tests')
    print(f'\n{checks} checks passed. No student or faculty records were inserted.')
finally:
    if server:
        server.terminate()
        server.wait(timeout=10)
    if re.fullmatch(r'bus_attendance_verify_[a-f0-9]{12}', DB):
        sql(f'DROP DATABASE IF EXISTS `{DB}`;')
