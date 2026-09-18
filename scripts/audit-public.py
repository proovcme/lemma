#!/usr/bin/env python3
"""Audit the exact Git staging area before publication; never scan runtime data."""
import json,os,pathlib,re,subprocess,sys
root=pathlib.Path(__file__).resolve().parents[1]
files=subprocess.check_output(['git','ls-files','-z'],cwd=root).decode().split('\0')
issues=[]
patterns=[r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',r'gh[pousr]_[A-Za-z0-9]{30,}',r'github_pat_[A-Za-z0-9_]{30,}',r'AKIA[0-9A-Z]{16}',r'/Users/[A-Za-z0-9._-]+/',r'\b(?:10\.\d{1,3}\.\d{1,3}\.\d{1,3}|192\.168\.\d{1,3}\.\d{1,3}|172\.(?:1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})\b']
private_terms=json.loads(os.environ.get('PUBLIC_DENY_TERMS','[]'))
for name in filter(None,files):
 p=root/name
 if not p.is_file():continue
 if name=='.env' or name.startswith(('backups/','storage/')) and name!='storage/.gitkeep' or p.suffix.lower() in {'.sqlite','.zip','.pem','.key','.p12'}:
  issues.append(name+': forbidden data artifact')
 try:s=p.read_text()
 except UnicodeDecodeError:continue
 for pat in patterns:
  if re.search(pat,s):issues.append(name+': restricted content')
 for term in private_terms:
  if term.casefold() in s.casefold():issues.append(name+': restricted term')
if issues:
 print('\n'.join(issues));sys.exit(1)
print(f'Public source audit passed: {len(files)-1} tracked files; no runtime data or private markers')
