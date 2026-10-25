#!/usr/bin/env python3
"""从 template-core.php.obfuscated.bak 生成开源可读 core 文件。"""
import re
from pathlib import Path

BASE = Path(__file__).resolve().parent
BAK = BASE / "template-core.php.obfuscated.bak"

HEADER = """<?php
/**
 * RiPro V5 核心逻辑（开源可读版）
 *
 * 由 template-core.php.obfuscated.bak 经 goto 展开 + global $wpdb 修复生成。
 */
defined('ABSPATH') || exit;

"""


def deobfuscate(body: str) -> str:
    pre = body
    pre = re.sub(r";(D8k[A-Za-z0-9_]+:)", r";\n\1", pre)
    pre = re.sub(r"\}(D8k[A-Za-z0-9_]+:)", r"}\n\1", pre)
    pre = re.sub(r";goto ", r";\ngoto ", pre)
    pre = re.sub(r"\}goto ", r"}\ngoto ", pre)
    pre = re.sub(r" goto ", r"\ngoto ", pre)
    elements = {"start": ""}
    key = "start"
    for line in pre.splitlines():
        s = line.strip()
        if re.fullmatch(r"D8k[A-Za-z0-9_]+:", s):
            key = s[:-1]
            elements.setdefault(key, "")
        else:
            elements[key] += line + "\n"

    def replace(elements, key="start"):
        if key not in elements:
            return ""
        code = elements[key]
        for g in re.findall(r"goto\s+(D8k[A-Za-z0-9_]+)\s*;", code):
            code = re.sub(
                r"goto\s+" + re.escape(g) + r"\s*;",
                replace(elements, g),
                code,
                count=1,
            )
        return code

    decoded = replace(elements, "start")
    decoded = re.sub(r"^\s*D8k[A-Za-z0-9_]+:\s*", "", decoded, flags=re.M)

    def inject_global(m):
        head, body_part, tail = m.group(1), m.group(2), m.group(3)
        if "$wpdb" in body_part and "global $wpdb" not in body_part[:300]:
            return head + "global $wpdb;" + body_part + tail
        return m.group(0)

    return re.sub(r"(function\s+[^{]+\{)(.*?)(\})", inject_global, decoded, flags=re.S)


def extract_class(body: str, name: str) -> str:
    start = body.find(f"class {name}")
    if start < 0:
        return ""
    i = start
    depth = 0
    started = False
    while i < len(body):
        c = body[i]
        if c == "{":
            depth += 1
            started = True
        elif c == "}":
            depth -= 1
            if started and depth == 0:
                return body[start : i + 1]
        i += 1
    return ""


def main():
    text = BAK.read_text(encoding="utf-8", errors="replace")
    body = text.split("*/", 1)[1].replace("?>", "").strip()
    decoded = deobfuscate(body)
    first_class = decoded.find("class ZB_Shop")
    bootstrap = decoded[:first_class].strip()
    classes = {
        name: extract_class(decoded, name) for name in ("ZB_Shop", "ZB_Pay", "ZB_Lic")
    }
    lic = classes["ZB_Lic"]
    lic_pos = decoded.find(f"class ZB_Lic")
    tail = ""
    if lic_pos >= 0 and lic:
        tail = decoded[lic_pos + len(lic) :].strip()

    (BASE / "template-core.bootstrap.php").write_text(HEADER + bootstrap + "\n", encoding="utf-8")
    for cls_name, code in classes.items():
        slug = cls_name.lower().replace("_", "-")
        (BASE / f"class-{slug}.php").write_text(HEADER + code + "\n", encoding="utf-8")
    (BASE / "template-core.tail.php").write_text(HEADER + tail + "\n", encoding="utf-8")
    loader = """<?php
/**
 * RiPro V5 核心入口（开源可读版）
 * 原混淆备份：template-core.php.obfuscated.bak
 * 重新生成：python3 inc/core/rebuild-open-core.py
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/template-core.bootstrap.php';
require_once __DIR__ . '/class-zb-shop.php';
require_once __DIR__ . '/class-zb-pay.php';
require_once __DIR__ . '/class-zb-lic.php';
require_once __DIR__ . '/template-core.tail.php';
"""
    (BASE / "template-core.php").write_text(loader, encoding="utf-8")
    print("OK: rebuilt open-source core in", BASE)


if __name__ == "__main__":
    main()
