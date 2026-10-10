#!/usr/bin/env python3
"""Deploy the validated, flat web artifact without logging credentials."""

import argparse
import ftplib
import os
from pathlib import Path
import re
import sys


class DeployError(Exception):
    """An error whose message is safe to display in a CI log."""


def read_config(environ):
    host = environ.get("FTP_SERVER") or environ.get("HOST")
    required = ("FTP_USER", "FTP_PASS")
    missing = [name for name in required if not environ.get(name)]
    if not host:
        missing.insert(0, "HOST oder FTP_SERVER")
    if missing:
        raise DeployError("Fehlende CI-Variablen: " + ", ".join(missing))

    port_text = environ.get("FTP_PORT", "28832")
    if not re.fullmatch(r"[0-9]+", port_text) or not 1 <= int(port_text) <= 65535:
        raise DeployError("FTP_PORT muss eine Zahl zwischen 1 und 65535 sein.")

    config = {
        "host": host,
        "port": int(port_text),
        "user": environ["FTP_USER"],
        "password": environ["FTP_PASS"],
        "directory": environ.get("FTP_SERVER_DIR", "."),
    }
    for value in (config["host"], config["user"], config["password"], config["directory"]):
        if not value or any(ord(char) < 32 or ord(char) == 127 for char in value):
            raise DeployError("FTP-Variablen enthalten einen leeren oder unzulässigen Wert.")
    return config


def artifact_files(directory):
    root = Path(directory)
    if root.is_symlink() or not root.is_dir():
        raise DeployError("Das Upload-Verzeichnis fehlt oder ist ungültig.")

    files = []
    for item in root.iterdir():
        name = item.name
        allowed = name == ".htaccess" or item.suffix in {".php", ".js", ".css", ".ics"}
        safe_name = not any(ord(char) < 32 or ord(char) == 127 for char in name)
        if item.is_symlink() or not item.is_file() or not allowed or not safe_name:
            raise DeployError("Das Upload-Verzeichnis enthält unerlaubte Dateien oder Unterordner.")
        files.append(item)

    if not any(item.name == "deployment_version.php" for item in files):
        raise DeployError("Im Upload-Paket fehlt deployment_version.php.")
    return sorted(files, key=lambda item: (item.name == "deployment_version.php", item.name))


def deploy(directory, environ, ftp_factory=None):
    config = read_config(environ)
    files = artifact_files(directory)
    if ftp_factory is None:
        ftp_factory = ftplib.FTP
    ftp = None
    phase = "Verbindung"
    try:
        ftp = ftp_factory(timeout=30)
        ftp.connect(config["host"], config["port"])
        phase = "Anmeldung"
        ftp.login(config["user"], config["password"])
        ftp.set_pasv(True)
        phase = "Zielverzeichnis"
        ftp.cwd(config["directory"])
        for item in files:
            phase = "Datei " + item.name
            temporary_name = "." + item.name + ".upload"
            with item.open("rb") as source:
                ftp.storbinary("STOR " + temporary_name, source)
            ftp.rename(temporary_name, item.name)
        phase = "Verbindungsabschluss"
        ftp.quit()
    except Exception:
        # FTP responses and exception messages can contain credentials. Never log them.
        raise DeployError(f"FTP-Upload fehlgeschlagen ({phase}). Zugang, Netzwerk und Zielverzeichnis prüfen.") from None
    finally:
        if ftp is not None:
            try:
                ftp.close()
            except Exception:
                pass
    return [item.name for item in files]


def main(argv=None, environ=None, ftp_factory=None, stdout=None, stderr=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("directory", nargs="?", default="upload/htdocs")
    args = parser.parse_args(argv)
    stdout = stdout if stdout is not None else sys.stdout
    stderr = stderr if stderr is not None else sys.stderr
    try:
        names = deploy(args.directory, os.environ if environ is None else environ, ftp_factory)
    except DeployError as error:
        print(str(error), file=stderr)
        return 1
    except Exception:
        print("FTP-Upload fehlgeschlagen. Upload-Paket und Konfiguration prüfen.", file=stderr)
        return 1
    for name in names:
        print("Hochgeladen: " + name, file=stdout)
    print(f"FTP-Upload erfolgreich: {len(names)} Dateien.", file=stdout)
    return 0


if __name__ == "__main__":
    sys.exit(main())
