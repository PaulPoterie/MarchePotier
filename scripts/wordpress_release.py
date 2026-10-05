"""Validate a published GitHub ZIP before handing it to the SVN deploy action.

No SVN credentials are read here. Only the deploy step receives that secret.
All staging paths belong to the disposable Actions runner, never to a live site.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import xml.etree.ElementTree as ET
import zipfile

REPOSITORY = "PaulPoterie/MarchePotier"
SLUG = "poterie-navarraise-market-manager"
SVN_URL = f"https://plugins.svn.wordpress.org/{SLUG}"
MAX_ARCHIVE = 25 * 1024 * 1024
MAX_EXPANDED = 100 * 1024 * 1024


def require(condition, message):
    if not condition:
        raise ValueError(message)


def run(*args, cwd=None):
    return subprocess.check_output(args, cwd=cwd)


def version_number(version):
    require(re.fullmatch(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)", version),
            "Expected a version such as 0.19.4 (three numbers, no suffix).")
    return tuple(map(int, version.split(".")))


def release_version(tag):
    require(tag.startswith("v"), "GitHub tags must use vX.Y.Z.")
    version_number(tag[1:])
    return tag[1:]


def header(content, name):
    pattern = rf"^[ \t/*#]*{re.escape(name)}:\s*([^\r\n]+)"
    values = re.findall(pattern, content.decode("utf-8-sig"), re.MULTILINE)
    require(len(values) == 1, f"Expected one {name} header.")
    return values[0].strip()


def runtime_path(name):
    return name in ("marche-potier.php", "readme.txt") or name.startswith(("includes/", "assets/"))


def read_package(archive, version):
    """Read only a bounded, regular-file archive with one known package root."""
    version_number(version)
    require(archive.stat().st_size <= MAX_ARCHIVE, "ZIP is unexpectedly large.")
    files, seen, expanded = {}, set(), 0
    with zipfile.ZipFile(archive) as package:
        require(len(package.infolist()) <= 2000, "Too many ZIP entries.")
        for entry in package.infolist():
            # ZipInfo may normalize backslashes or truncate NULs on Windows; validate the raw name.
            name = entry.orig_filename
            require("\\" not in name and ":" not in name and "\0" not in name,
                    "Invalid ZIP path.")
            parts = PurePosixPath(name).parts
            require(parts and parts[0] == SLUG and all(p not in (".", "..") for p in parts),
                    "ZIP must contain only the expected plugin directory.")
            require(name.rstrip("/") == "/".join(parts), "Non-canonical ZIP path.")
            mode = entry.external_attr >> 16
            kind = stat.S_IFMT(mode)
            require(kind in (0, stat.S_IFREG, stat.S_IFDIR) and not (entry.flag_bits & 1),
                    "Links, special files and encrypted entries are forbidden.")
            require(name.casefold() not in seen, "Duplicate ZIP entry.")
            seen.add(name.casefold())
            if entry.is_dir():
                require(kind in (0, stat.S_IFDIR), "Invalid directory entry.")
                continue
            require(len(parts) > 1 and kind in (0, stat.S_IFREG), "Invalid file entry.")
            relative = "/".join(parts[1:])
            require(runtime_path(relative), f"Unexpected distributed file: {relative}")
            require(not any(p.startswith(".") for p in parts), "Hidden files are forbidden.")
            expanded += entry.file_size
            require(expanded <= MAX_EXPANDED, "Expanded ZIP is unexpectedly large.")
            files[relative] = package.read(entry)
    require("marche-potier.php" in files and "readme.txt" in files, "Missing plugin headers.")
    require(header(files["marche-potier.php"], "Version") == version, "Plugin version differs.")
    require(header(files["readme.txt"], "Stable tag") == version, "Stable tag differs.")
    require(header(files["marche-potier.php"], "Text Domain") == SLUG, "Wrong translation domain.")
    return files


def git_files(repo, tag):
    git = ["git", "-c", f"safe.directory={repo.as_posix()}", "-C", str(repo)]
    commit = run(*git, "rev-parse", f"refs/tags/{tag}^{{commit}}").decode().strip()
    subprocess.run([*git, "merge-base", "--is-ancestor", commit, "origin/main"], check=True)
    entries = run(*git, "ls-tree", "-r", "-z", commit, "--",
                  "marche-potier.php", "readme.txt", "includes", "assets").split(b"\0")
    files = {}
    for entry in filter(None, entries):
        attributes, name = entry.split(b"\t", 1)
        mode, kind, object_id = attributes.split()
        require(mode in (b"100644", b"100755") and kind == b"blob", "Non-file in Git package.")
        files[name.decode("utf-8")] = run(*git, "cat-file", "blob", object_id.decode())
    return commit, files


def same_files(actual, expected):
    require(actual.keys() == expected.keys(), "Package file list differs from the reference.")
    for name, content in expected.items():
        require(actual[name] == content, f"File content differs: {name}")


def directory_files(directory):
    files = {}
    for path in directory.rglob("*"):
        require(not path.is_symlink(), "Unexpected symbolic link.")
        if path.is_file():
            files[path.relative_to(directory).as_posix()] = path.read_bytes()
    return files


def output(name, value):
    if os.environ.get("GITHUB_OUTPUT"):
        with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as target:
            target.write(f"{name}={value}\n")


def prepare(repo, work, tag):
    version = release_version(tag)
    require(not work.exists(), "Use a fresh staging directory.")
    work.mkdir(parents=True)
    release = json.loads(run("gh", "api", f"repos/{REPOSITORY}/releases/tags/{tag}"))
    require(release["tag_name"] == tag and not release["draft"] and not release["prerelease"]
            and release["published_at"], "Only published stable GitHub releases can be deployed.")
    name = f"{SLUG}-{version}.zip"
    assets = [asset for asset in release["assets"] if asset["name"] == name]
    require(len(assets) == 1 and assets[0]["state"] == "uploaded", "Release ZIP is missing or incomplete.")
    require(assets[0]["size"] <= MAX_ARCHIVE, "Release ZIP is unexpectedly large.")
    subprocess.run(["gh", "release", "download", tag, "--repo", REPOSITORY,
                    "--pattern", name, "--dir", str(work)], check=True)
    archive = work / name
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    require(assets[0].get("digest") == f"sha256:{digest}", "GitHub ZIP digest differs.")
    require(archive.stat().st_size == assets[0]["size"], "GitHub ZIP size differs.")
    files = read_package(archive, version)
    commit, reference = git_files(repo, tag)
    same_files(files, reference)
    package_dir = work / SLUG
    for relative, content in files.items():
        destination = package_dir / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(content)
    manifest = {name: hashlib.sha256(content).hexdigest() for name, content in sorted(files.items())}
    report = {"version": version, "tag": tag, "commit": commit, "asset_id": assets[0]["id"],
              "sha256": digest, "files": manifest}
    (work / "package.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    output("version", version)
    output("build_dir", package_dir.as_posix())
    print(f"Verified {tag}: {len(files)} files, SHA-256 {digest}.")


def svn(*args):
    return run("svn", "--non-interactive", *args)


def svn_names(url):
    document = ET.fromstring(svn("list", "--xml", url))
    return [entry.findtext("name") for entry in document.findall(".//entry")]


def check_advance(version, tags, stable=None):
    proposed = version_number(version)
    previous = [version_number(tag) for tag in tags]
    if stable:
        previous.append(version_number(stable))
    require(not previous or proposed >= max(previous), "Refusing to downgrade the SVN version.")
    require(version not in tags, "An existing SVN tag must be verified, never overwritten.")


def verify_tag(work, version):
    destination = work / "svn-export"
    require(not destination.exists(), "Use a fresh SVN export directory.")
    svn("export", "--ignore-externals", f"{SVN_URL}/tags/{version}", str(destination))
    same_files(directory_files(destination), directory_files(work / SLUG))
    print(f"SVN tag {version} is byte-for-byte identical to the GitHub ZIP.")


def preflight(work, version):
    version_number(version)
    tags = svn_names(f"{SVN_URL}/tags/")
    if version in tags:
        verify_tag(work, version)
        output("deploy", "false")
        print("Already published with identical content; no changes will be made.")
        return
    trunk = svn_names(f"{SVN_URL}/trunk/")
    require(not trunk or "readme.txt" in trunk, "Unexpected SVN trunk; inspect it before publishing.")
    stable = header(svn("cat", f"{SVN_URL}/trunk/readme.txt"), "Stable tag") if trunk else None
    check_advance(version, tags, stable)
    output("deploy", "true")
    print(f"SVN is ready for version {version}; existing tags will be preserved.")


def configure_check(repo, work):
    """Map the audit outside the package, so neither it nor config is distributed."""
    diagnostics = work / "diagnostics"
    diagnostics.mkdir()
    diagnostics.chmod(0o777)  # Container must be able to write its disposable audit.
    config = {"phpVersion": "8.3", "config": {"WP_DEBUG": True, "WP_DEBUG_DISPLAY": False},
              "mappings": {
                  "wp-content/mu-plugins/marcpo-release-check.php":
                      str(repo / "scripts/wordpress-check-runtime.php"),
                  "wp-content/marcpo-check-report": str(diagnostics)}}
    path = repo / ".wp-env.json"
    require(not path.exists(), "Refusing to replace a wp-env configuration.")
    path.write_text(json.dumps(config, indent=2), encoding="utf-8")


def audit_check(work):
    audit = json.loads((work / "diagnostics/runtime.json").read_text(encoding="utf-8"))
    require(audit["target_active"] and audit["target_loaded"] and audit["debug"]
            and audit["runtime_checks"] > 0, "Plugin Check runtime was not actually enabled.")
    report = json.loads((work / "package.json").read_text(encoding="utf-8"))
    current = {name: hashlib.sha256(content).hexdigest()
               for name, content in directory_files(work / SLUG).items()}
    require(current == report["files"], "Plugin Check changed the package.")
    print(f"Runtime confirmed: {len(audit['checks'])} checks, including {audit['runtime_checks']} runtime checks.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("prepare", "preflight", "verify", "configure-check", "audit-check"))
    parser.add_argument("--work", type=Path, required=True)
    parser.add_argument("--repo", type=Path, default=Path.cwd())
    parser.add_argument("--tag")
    args = parser.parse_args()
    repo, work = args.repo.resolve(), args.work.resolve()
    if args.command == "prepare":
        prepare(repo, work, args.tag or "")
    elif args.command == "configure-check":
        configure_check(repo, work)
    elif args.command == "audit-check":
        audit_check(work)
    else:
        report = json.loads((work / "package.json").read_text(encoding="utf-8"))
        if args.command == "preflight":
            preflight(work, report["version"])
        else:
            verify_tag(work, report["version"])


if __name__ == "__main__":
    main()
