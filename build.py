"""Build the Magento 2 package: copies the ArionPay PHP SDK into lib/, then zips the module as app/code/ArionPay/Payment.
   python3 build.py  ->  dist/arionpay-magento2.zip  (unzip in the Magento root)"""
import os, shutil, zipfile, pathlib
HERE = pathlib.Path(__file__).resolve().parent
SDK = HERE.parent / "arionpay-php"
lib = HERE / "lib" / "arionpay-php"
if lib.exists():
    shutil.rmtree(lib)
(lib / "src").mkdir(parents=True)
for f in ("autoload.php", "LICENSE"):
    shutil.copy(SDK / f, lib / f)
for f in sorted((SDK / "src").glob("*.php")):
    shutil.copy(f, lib / "src" / f.name)
(HERE / "dist").mkdir(exist_ok=True)
out = HERE / "dist" / "arionpay-magento2.zip"
skip = {"dist", "build.py", "__pycache__"}
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(HERE):
        rel_root = pathlib.Path(root).relative_to(HERE)
        if rel_root.parts and rel_root.parts[0] in skip:
            continue
        dirs[:] = sorted(d for d in dirs if d not in skip)
        for name in sorted(files):
            if name in skip:
                continue
            p = pathlib.Path(root) / name
            z.write(p, "app/code/ArionPay/Payment/" + str(p.relative_to(HERE)))
print(out, os.path.getsize(out), "bytes")
