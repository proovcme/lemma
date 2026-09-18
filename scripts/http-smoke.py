#!/usr/bin/env python3
"""Exercise the installed application over HTTP in CI."""
import http.cookiejar,os,re,sys,urllib.parse,urllib.request,io,zipfile
base=sys.argv[1].rstrip('/')
mode=sys.argv[2] if len(sys.argv)>2 else 'work'
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(path):
 with opener.open(base+path,timeout=30) as r:
  html=r.read().decode();assert r.status==200
  assert not any(x in html for x in ['Fatal error','Uncaught PDO','Страница временно недоступна']),path
  return html
login=get('/login')
csrf=re.search(r'name="_csrf" value="([^"]+)"',login).group(1)
if mode=='demo':
 persona='director'
 data={'_csrf':csrf,'persona':persona};route='/demo-login'
else:
 assert 'Демо доступ' not in login
 data={'_csrf':csrf,'login':'0001','password':os.environ['ADMIN_PASSWORD']};route='/login'
with opener.open(base+route,urllib.parse.urlencode(data).encode(),timeout=30) as r:
 html=r.read().decode();assert '/login' not in r.url,r.url
 assert 'ЛЕММА' in html or 'Лемма' in html
for path in ['/my-day','/tasks','/projects','/reports','/time']:
 html=get(path)
 if mode=='demo' and path=='/projects':assert 'D-101' in html and 'D-104' in html
if mode=='work':
 for path in ['/team','/knowledge','/admin/users','/admin/database-export']:
  get(path)
if mode=='work':
 html=get('/admin/database-export')
 csrf=re.search(r'name="_csrf" value="([^"]+)"',html).group(1)
 with opener.open(base+'/admin/database-export/download',urllib.parse.urlencode({'_csrf':csrf}).encode(),timeout=60) as r:
  assert r.headers.get_content_type()=='application/zip','Database export did not return ZIP'
  with zipfile.ZipFile(io.BytesIO(r.read())) as z:
   sql=z.read('database/locia.sql').decode()
   assert 'CREATE TABLE' in sql and 'users' in sql
for path in ['/.env','/storage/test','/app/bootstrap.php','/locia-update/health','/locia-notify/health']:
 try:
  opener.open(base+path,timeout=15);raise AssertionError('Private path exposed: '+path)
 except urllib.error.HTTPError as e:assert e.code==404,(path,e.code)
print('HTTP smoke passed:',mode,'login, working pages, private path boundary')
