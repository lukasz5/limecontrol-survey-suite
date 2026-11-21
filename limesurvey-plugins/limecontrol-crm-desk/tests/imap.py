# SPDX-License-Identifier: GPL-2.0-or-later
"""Exercise the native Zend IMAP client and the exact Desk importer on a loopback TLS mailbox."""
from pathlib import Path
import subprocess,socketserver,ssl,threading,os,json,tempfile,atexit,sys
from shutil import which
if os.environ.get('LC_DESK_SYNTHETIC')!='1' or len(sys.argv)!=3:
    raise SystemExit('Usage: LC_DESK_SYNTHETIC=1 python3 imap.py DISPOSABLE_LIMESURVEY_ROOT TEAM_ID')
tree=Path(sys.argv[1]).resolve()
team=int(sys.argv[2])
if not (tree/'application/commands/console.php').is_file() or team<1:
    raise SystemExit('Expected a disposable CE tree with an active CRM group')
tmp=tempfile.TemporaryDirectory()
atexit.register(tmp.cleanup)
base=Path(tmp.name)
key=base/'imap.key';cert=base/'imap.crt'
subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost,IP:127.0.0.1','-keyout',str(key),'-out',str(cert)],capture_output=True,check=True)
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key)
eml=b'Subject: IMAP test\r\nFrom: qa@example.invalid\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nIMAP test case'
class Server(socketserver.ThreadingTCPServer):
    def get_request(self):
        conn,addr=super().get_request()
        return ctx.wrap_socket(conn,server_side=True),addr
class Session(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'* OK synthetic IMAP ready\r\n')
        while line:=self.rfile.readline():
            tag,command,args=(line.rstrip().split(b' ',2)+[b''])[:3]
            if command==b'LOGIN' or command==b'LOGOUT': output=b''
            elif command==b'EXAMINE':output=b'* 1 EXISTS\r\n* OK [UIDVALIDITY 4] test\r\n'
            elif command==b'SEARCH':output=b'* SEARCH 1\r\n'
            elif command==b'FETCH' and b'RFC822.SIZE' in args:output=b'* 1 FETCH (UID 11 RFC822.SIZE '+str(len(eml)).encode()+b')\r\n'
            elif command==b'FETCH':output=b'* 1 FETCH (UID 11 INTERNALDATE "21-Nov-2026 01:00:00 +0000" RFC822 {'+str(len(eml)).encode()+b'}\r\n'+eml+b')\r\n'
            else:raise AssertionError(command)
            self.wfile.write(output+tag+b' OK complete\r\n')
            if command==b'LOGOUT':return
srv=Server(('127.0.0.1',0),Session);srv.daemon_threads=True
threading.Thread(target=srv.serve_forever,daemon=True).start()
port=srv.server_address[1]
env={**os.environ,'LIMECONTROL_CRM_MAILDIRS':'{}',
'LIMECONTROL_CRM_IMAP':json.dumps({str(team):{'host':'127.0.0.1','port':port,'username':'qa','passwordEnv':'LC_TEST_IMAP_PASS'}}),'LC_TEST_IMAP_PASS':'synthetic-42'}
try:
  for expected in [f'{team}: 1',f'{team}: 0']:
    cmd=[os.environ.get('LC_TEST_PHP') or which('php'),'-d','openssl.cafile='+str(cert),'application/commands/console.php','plugin','index','--target=LimeControlCrmDesk','--function=mail']
    p=subprocess.run(cmd,env=env,cwd=tree,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,timeout=20)
    assert p.returncode==0 and expected in p.stdout,(p.returncode,p.stdout[:1700])
  print('OK IMAP TLS/EXAMINE, native MIME and UID idempotency')
finally:srv.shutdown();srv.server_close()
