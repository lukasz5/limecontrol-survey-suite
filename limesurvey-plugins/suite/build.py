#!/usr/bin/env python3
"""Build the self-hosted CRM, feedback, desk, store connectors and bilingual survey archives."""
from hashlib import sha256
from pathlib import Path
from tempfile import TemporaryDirectory
from xml.etree import ElementTree
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
import subprocess
import sys

HERE = Path(__file__).resolve().parent
PLUGINS = HERE.parent
ROOT = PLUGINS.parent
OUT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else HERE / "dist"
OUT.mkdir(exist_ok=True, parents=True)
version = "0.1.0"

def archive(name, sources):
    target = OUT / f"{name}-{version}.zip"
    with ZipFile(target, "w") as bundle:
        for entry, path in sorted(sources.items()):
            info = ZipInfo(entry, (2026, 1, 1, 0, 0, 0))
            info.external_attr = 0o100644 << 16
            info.compress_type = ZIP_DEFLATED
            bundle.writestr(info, path.read_bytes())

for folder, name in [("limecontrol-crm","LimeControlCrm"), ("limecontrol-feedback","LimeControlFeedback"), ("limecontrol-crm-desk","LimeControlCrmDesk")]:
    source = PLUGINS / folder / name
    if ElementTree.parse(source / "config.xml").findtext("metadata/version") != version:
        raise ValueError(f"Version mismatch for {name}")
    entries = {str(p.relative_to(source.parent)): p for p in source.rglob("*") if p.is_file() and not p.name.startswith(".")}
    entries.setdefault(f"{name}/LICENSE", ROOT / "LICENSE")
    archive(name, entries)
with TemporaryDirectory() as tmp:
    subprocess.run(["node", HERE / "templates.mjs", tmp], check=True)
    archive("LimeControlSurveys", {p.name: p for p in Path(tmp).glob("*.lss")})
subprocess.run(["php", ROOT / "commerce-connectors/build.php", OUT], check=True)
expected = {
    f"LimeControlCrm-{version}.zip": ["LimeControlCrm/config.xml"],
    f"LimeControlFeedback-{version}.zip": ["LimeControlFeedback/config.xml"],
    f"LimeControlCrmDesk-{version}.zip": ["LimeControlCrmDesk/config.xml"],
    f"LimeControlSurveys-{version}.zip": ["product-quality.lss"],
    f"limecontrol-feedback-woocommerce-{version}.zip": ["limecontrol-feedback-woocommerce/limecontrol-feedback.php"],
    f"limecontrol-feedback-prestashop-{version}.zip": ["limecontrolfeedback/limecontrolfeedback.php"],
    "limecontrol_feedback.ocmod.zip": ["install.json", "admin/controller/module/limecontrol_feedback.php"],
}
for platform, command in [("shoper", "cli.php"), ("shopify", "worker.php")]:
    root = f"limecontrol-feedback-{platform}"
    expected[f"{root}-{version}.zip"] = [
        f"{root}/{entry}" for entry in ["public/index.php", command, "common/Feedback.php", "hosted-runtime/RemoteConnector.php"]
    ]
checks = []
for name, entries in sorted(expected.items()):
    path = OUT / name
    with ZipFile(path) as bundle:
        if set(entries).difference(bundle.namelist()) or bundle.testzip() is not None:
            raise RuntimeError(f"Invalid archive {path}")
    checks.append(f"{sha256(path.read_bytes()).hexdigest()}  {path.name}\n")
(OUT / "SHA256SUMS").write_text("".join(checks))
print(f"{len(checks)} archives verified in {OUT}")
