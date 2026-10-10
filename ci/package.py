"""Build the FTP artifact without source control, runtime secrets or SQL files."""

import os
from pathlib import Path
import re
import shutil
import sys


WEB_EXTENSIONS = {".php", ".js", ".css", ".ics"}


def build_package(root: Path, destination: Path, commit: str) -> int:
    if not re.fullmatch(r"[0-9a-f]{40,64}", commit):
        raise ValueError("CI_COMMIT_SHA fehlt oder ist ungültig")
    if destination.exists():
        raise ValueError("Upload-Verzeichnis existiert bereits; frischen Checkout verwenden")
    files = sorted(
        path for path in root.iterdir()
        if path.is_file() and not path.is_symlink()
        and (path.suffix in WEB_EXTENSIONS or path.name == ".htaccess")
    )
    names = {path.name for path in files}
    if not {"index.php", "config.php", ".htaccess"}.issubset(names):
        raise ValueError("Erforderliche Webdateien fehlen")
    destination.mkdir(parents=True)
    for path in files:
        shutil.copyfile(path, destination / path.name)
    marker = (
        "<?php\n"
        "header('Content-Type: application/json; charset=utf-8');\n"
        "header('Cache-Control: no-store');\n"
        f"echo '{{\"commit\":\"{commit}\"}}';\n"
    )
    (destination / "deployment_version.php").write_text(marker, encoding="utf-8")
    return len(files) + 1


def main() -> int:
    root = Path(__file__).resolve().parent.parent
    try:
        count = build_package(root, root / "upload" / "htdocs", os.getenv("CI_COMMIT_SHA", ""))
    except (ValueError, OSError) as error:
        print(f"Paket konnte nicht erstellt werden: {error}", file=sys.stderr)
        return 1
    print(f"Webpaket erstellt: {count} Dateien")
    return 0


if __name__ == "__main__":
    sys.exit(main())
