"""Local CE browser acceptance with TLS readback, SMTP sink, and isolated surveys."""
import argparse, base64, email, hashlib, hmac, json, os, re, secrets, socketserver, ssl, subprocess, tempfile, threading, time
import urllib.parse, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

root = Path(__file__).resolve().parent
cfg = argparse.ArgumentParser()
for name in ("tree","base","templates","php"):
    cfg.add_argument("--"+name,required=True)
args=cfg.parse_args()
base=args.base.rstrip("/")
assert urllib.parse.urlsplit(base).hostname in {"localhost","127.0.0.1"} and os.environ.get("LCF_NATIVE_QA")=="disposable"
secret=os.environ["LCF_SUITE_SECRET"].encode()
shop="qa"+secrets.token_hex(4)
env={**os.environ,"YII_CONSOLE_COMMANDS":str(root),"LCF_NATIVE_STORE":shop}
def post(url,body,headers):
    req=urllib.request.Request(url,data=body,headers=headers)
    with urllib.request.urlopen(req,timeout=40) as response: return response.read()
def rpc(method,params):
    reply=json.loads(post(base+"/index.php/admin/remotecontrol",json.dumps({"method":method,"params":params,"id":1}).encode(),{"Content-Type":"application/json"}))
    if reply.get("error"): raise RuntimeError(method+" failed")
    return reply.get("result")
def signed(body):
    stamp=str(int(time.time()))
    return {"Content-Type":"application/json","X-LC-Store":shop,"X-LC-Timestamp":stamp,"X-LC-Signature":hmac.new(secret,stamp.encode()+b"."+body,hashlib.sha256).hexdigest()}
def order(rev,items,returned):
    return {"version":1,"event_id":shop+"-"+str(rev),"order_id":"901","revision":rev,"status":"eligible","occurred_at":int(time.time())-30,
        "email":"synthetic-buyer@example.invalid","items":items,"returned_items":returned}
keep={"line_id":"2","sku":"KEEP","label":"<em>Kept item</em>","quantity":1}
removed={"line_id":"1","sku":"RETURNED","label":"Removed item","quantity":1}
snapshot=order(2,[keep],[removed])
messages=[]
class Readback(BaseHTTPRequestHandler):
    def do_POST(self):
        data=self.rfile.read(int(self.headers["Content-Length"]))
        sig=hmac.new(secret,self.headers["X-LC-Timestamp"].encode()+b"."+data,hashlib.sha256).hexdigest()
        if self.headers["X-LC-Store"]!=shop or not hmac.compare_digest(sig,self.headers["X-LC-Signature"]) or json.loads(data)!={"version":1,"order_id":"901"}:
            self.send_error(401);return
        body=json.dumps(snapshot).encode();self.send_response(200)
        for k,v in signed(body).items(): self.send_header(k,v)
        self.send_header("Content-Length",str(len(body)))
        self.end_headers();self.wfile.write(body)
    def log_message(self,*args): pass
class Mail(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b"220 localhost QA SMTP\r\n")
        while True:
            line=self.rfile.readline()
            if not line:return
            cmd=line.split(b" ",1)[0].strip().upper()
            if cmd==b"QUIT": self.wfile.write(b"221 bye\r\n");return
            if cmd==b"DATA":
                self.wfile.write(b"354 End with dot\r\n");body=b""
                while (line:=self.rfile.readline()) not in (b".\r\n",b""): body+=line
                messages.append(email.message_from_bytes(body))
            if cmd==b"RCPT" and b"example.invalid" not in line: raise RuntimeError("Only synthetic mail")
            self.wfile.write(b"250 OK\r\n")
with tempfile.TemporaryDirectory() as directory:
    td=Path(directory); cert=td/"cert.pem"; key=td/"key.pem"
    subprocess.run(["openssl","req","-x509","-newkey","rsa:2048","-nodes","-days","1","-subj","/CN=127.0.0.1",
                    "-addext","subjectAltName=IP:127.0.0.1","-keyout",str(key),"-out",str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    env["SSL_CERT_FILE"]=str(cert)
    env["CURL_CA_BUNDLE"]=str(cert)
    http=ThreadingHTTPServer(("127.0.0.1",0),Readback)
    tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); tls.load_cert_chain(cert,key);http.socket=tls.wrap_socket(http.socket,server_side=True)
    smtp=socketserver.ThreadingTCPServer(("127.0.0.1",0),Mail)
    for server in (http,smtp):threading.Thread(target=server.serve_forever,daemon=True).start()
    def probe(*params):
        proc=subprocess.run([args.php,"-d","curl.cainfo="+str(cert),"-d","openssl.cafile="+str(cert),"application/commands/console.php","feedbackprobe",*map(str,params)],cwd=args.tree,env=env,
                            capture_output=True,text=True,timeout=60)
        lines=[line[12:] for line in proc.stdout.splitlines() if line.startswith("FEEDBACK_QA ")]
        if not lines: raise RuntimeError("Native probe "+str(params[0])+" failed: "+(proc.stdout+proc.stderr)[:2200])
        return json.loads(lines[-1])
    def imported(file):
        data=base64.b64encode((Path(args.templates)/file).read_bytes()).decode()
        sid=rpc("import_survey",[session,data,"lss",shop+" "+file])
        if not isinstance(sid,int):raise RuntimeError("Survey import failed: "+str(sid))
        rpc("set_survey_properties",[session,sid,{"admin":"Synthetic QA","adminemail":"qa@example.invalid"}])
        for name in ("activate_survey","activate_tokens"):
            result=rpc(name,[session,sid])
            if result.get("status")!="OK":raise RuntimeError(name+" "+str(result))
        return sid
    session=rpc("get_session_key",[os.environ["LCF_QA_USER"],os.environ["LCF_QA_PASSWORD"]])
    assert isinstance(session,str)
    product,anon,returns=(imported(f) for f in ("product-quality.lss","anonymous-service.lss","returns.lss"))
    team=probe("setup",product,anon,returns,http.server_port)["team"]
    try:
        body=json.dumps(order(1,[removed,keep],[])).encode()
        url=base+"/index.php/plugins/unsecure?plugin=LimeControlFeedback&function=ingest"
        if json.loads(post(url,body,signed(body)))!={"status":"accepted"}:raise RuntimeError("Unexpected webhook result")
        if json.loads(post(url,body,signed(body)))!={"status":"duplicate"}:raise RuntimeError("Webhook was not idempotent")
        if probe("worker",smtp.server_address[1])["processed"]!=2:raise RuntimeError("Expected two independent campaigns")
        if probe("worker",smtp.server_address[1])["processed"]!=1 or len(messages)!=3: raise RuntimeError("Expected the newly eligible return campaign")
        forms=[]
        optout=None
        for msg in messages:
            body=msg.get_payload(decode=True).decode(msg.get_content_charset() or "utf-8")
            url=next(line.strip() for line in body.splitlines() if "survey/index" in line)
            parsed=urllib.parse.urlsplit(url); sid=int(urllib.parse.parse_qs(parsed.query)["sid"][0])
            assert sid in {product,anon,returns}
            target=base+parsed.path+"?"+parsed.query
            quit_url=urllib.parse.urlsplit(next(line.strip() for line in body.splitlines() if "function=unsubscribe" in line))
            optout=base+quit_url.path+"?"+quit_url.query
            forms.append(dict(url=target,sid=sid,kind={product:"product",anon:"anonymous",returns:"returns"}[sid],questions=probe("questions",sid)))
        subprocess.run(["node",str(root/"respondent.cjs")],input=json.dumps(dict(base=base,forms=forms,optout=optout)),text=True,check=True,env=env)
        proof=probe("verify",product,anon,team)
        if probe("worker",smtp.server_address[1])["processed"]!=0 or len(messages)!=3:raise RuntimeError("Duplicate dispatch")
        print(json.dumps(dict(signedWebhook=True,signedTlsPreflight=True,localSmtp=len(messages),browserSubmissions=3,variantComparison=True,reports=True,unsubscribeConfirmed=True,**proof)))
    finally:
        probe("unmap")
        rpc("release_session_key",[session])
        http.shutdown(); smtp.shutdown()
