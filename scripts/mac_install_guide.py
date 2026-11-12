"""macOS 安装包(DMG)里放的「安装说明.png」。

【2026-11-12 用户要求】原来 DMG 里放一份 安装教程.txt,别的软件都是一张图文说明。
这里按品牌名现画一张:四步(拖进应用程序 → 未打开点完成 → 设置里点仍要打开 →
弹窗再点仍要打开)+ 「已损坏」时的终端命令。弹窗 / 设置页是照 macOS 15 起的样子画的
示意图,不是截图(截图里的名字没法换成各品牌)。

用法: python3 mac_install_guide.py <App 名> <输出.png> [App 图标 .png]
画不出来(没字体等)退出码非 0,调用方退回 txt。
"""

from __future__ import annotations

import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

W = 800
PAD = 60
SCALE = 2  # 按 2 倍画,Retina 上不糊;最后存的就是 2 倍图

# macOS runner 自带的中文字体(PingFang 在 AssetsV2 里,路径不固定,不用)。
_FONT_CANDIDATES = {
    'regular': [
        ('/System/Library/Fonts/Hiragino Sans GB.ttc', 0),
        ('/System/Library/Fonts/STHeiti Light.ttc', 0),
        ('/System/Library/Fonts/STHeiti Medium.ttc', 0),
    ],
    'bold': [
        ('/System/Library/Fonts/STHeiti Medium.ttc', 0),
        ('/System/Library/Fonts/Hiragino Sans GB.ttc', 1),
        ('/System/Library/Fonts/Hiragino Sans GB.ttc', 0),
    ],
}


def _font(kind: str, size: float) -> ImageFont.FreeTypeFont:
    for path, index in _FONT_CANDIDATES[kind]:
        if Path(path).exists():
            try:
                return ImageFont.truetype(path, int(size * SCALE), index=index)
            except OSError:
                continue
    raise RuntimeError('没有可用的中文字体')


def s(v: float) -> int:
    return int(round(v * SCALE))


# 颜色
BG_TOP = (240, 246, 255)
BG = (255, 255, 255)
TEXT = (34, 34, 38)
MUTED = (110, 110, 118)
PANEL = (232, 233, 237)
RED = (230, 30, 40)
BLUE = (10, 122, 255)
DIALOG = (246, 246, 248)
BTN_GRAY = (222, 222, 226)


def _text_w(d: ImageDraw.ImageDraw, t: str, f) -> int:
    return int(d.textlength(t, font=f))


def _ellipsize(d, t: str, f, max_w: int) -> str:
    if _text_w(d, t, f) <= max_w:
        return t
    while t and _text_w(d, t + '…', f) > max_w:
        t = t[:-1]
    return t + '…'


_NO_LINE_START = set('，。、：；！？）」』”’,.:;!?)')


def _wrap(d, t: str, f, max_w: int) -> list[str]:
    """按字宽折行(中文逐字,够用)。"""
    lines, cur = [], ''
    for ch in t:
        # 标点不放到行首(宁可这一行略超一点)
        if _text_w(d, cur + ch, f) > max_w and cur and ch not in _NO_LINE_START:
            lines.append(cur)
            cur = ch
        else:
            cur += ch
    if cur:
        lines.append(cur)
    # 折行处的空格不留在行首
    return [ln.lstrip(' ') for ln in lines]


def _warn_icon(d, cx: int, top: int, size: int):
    """黄色警告三角 + 感叹号。"""
    h = int(size * 0.88)
    pts = [(cx, top), (cx - size // 2, top + h), (cx + size // 2, top + h)]
    d.polygon(pts, fill=(255, 196, 0), outline=(214, 150, 0))
    bw = max(2, size // 12)
    d.rounded_rectangle([cx - bw, top + h * 0.32, cx + bw, top + h * 0.68],
                        radius=bw, fill=(255, 255, 255))
    d.ellipse([cx - bw * 1.2, top + h * 0.76, cx + bw * 1.2, top + h * 0.76 + bw * 2.4],
              fill=(255, 255, 255))


def _highlight(d, box, radius):
    x0, y0, x1, y1 = box
    m = s(5)
    d.rounded_rectangle([x0 - m, y0 - m, x1 + m, y1 + m], radius=radius + m,
                        outline=RED, width=s(3))


def _cursor(d, x: int, y: int):
    """鼠标指针:点在 (x, y)。"""
    k = SCALE * 1.15
    pts = [(0, 0), (0, 17), (4.5, 13), (7.5, 20), (10.5, 18.5), (7.5, 12), (13, 12)]
    poly = [(x + px * k, y + py * k) for px, py in pts]
    d.polygon(poly, fill=(20, 20, 20), outline=(255, 255, 255))
    d.line(poly + [poly[0]], fill=(255, 255, 255), width=max(1, SCALE))


def _button(d, box, label, f, primary=False, outline=False):
    x0, y0, x1, y1 = box
    r = (y1 - y0) // 2
    fill = BLUE if primary else BTN_GRAY
    d.rounded_rectangle(box, radius=r, fill=fill,
                        outline=(BLUE if outline else None), width=s(2) if outline else 0)
    tw = _text_w(d, label, f)
    th = f.size
    d.text(((x0 + x1 - tw) / 2, (y0 + y1 - th) / 2 - s(1)), label, font=f,
           fill=(255, 255, 255) if primary else TEXT)


def _panel(img, d, top: int, height: int):
    d.rounded_rectangle([s(PAD), top, s(W - PAD), top + height], radius=s(12),
                        fill=PANEL, outline=(222, 223, 228), width=s(1))


def _dialog(d, cx, top, width, title, body, buttons, f_title, f_body, f_btn,
            hl_index: int):
    """macOS 风格居中提示框。buttons: [(文字, primary)],竖排或横排(两个时横排)。返回底边。"""
    x0, x1 = cx - width // 2, cx + width // 2
    inner = width - s(48)
    title_lines = _wrap(d, title, f_title, inner)
    body_lines = _wrap(d, body, f_body, inner)
    y = top + s(24)
    icon = s(54)
    y_icon = y
    y += icon + s(16)
    y_title = y
    y += len(title_lines) * int(f_title.size * 1.35) + s(6)
    y_body = y
    y += len(body_lines) * int(f_body.size * 1.45) + s(18)
    bh = s(30)
    horizontal = len(buttons) == 2
    y_btn = y
    y += (bh if horizontal else len(buttons) * (bh + s(10)) - s(10)) + s(20)
    bottom = y
    d.rounded_rectangle([x0, top, x1, bottom], radius=s(18), fill=DIALOG,
                        outline=(210, 210, 216), width=s(1))
    _warn_icon(d, x0 + s(24) + icon // 2, y_icon, icon)
    for i, ln in enumerate(title_lines):
        d.text((x0 + s(24), y_title + i * int(f_title.size * 1.35)), ln,
               font=f_title, fill=TEXT)
    for i, ln in enumerate(body_lines):
        d.text((x0 + s(24), y_body + i * int(f_body.size * 1.45)), ln,
               font=f_body, fill=TEXT)
    boxes = []
    if horizontal:
        gap = s(10)
        bw = (width - s(48) - gap) // 2
        for i, (label, primary) in enumerate(buttons):
            bx = x0 + s(24) + i * (bw + gap)
            boxes.append((bx, y_btn, bx + bw, y_btn + bh, label, primary))
    else:
        for i, (label, primary) in enumerate(buttons):
            by = y_btn + i * (bh + s(10))
            boxes.append((x0 + s(24), by, x1 - s(24), by + bh, label, primary))
    for bx0, by0, bx1, by1, label, primary in boxes:
        _button(d, (bx0, by0, bx1, by1), label, f_btn, primary=primary)
    hx0, hy0, hx1, hy1, _, _ = boxes[hl_index]
    _highlight(d, (hx0, hy0, hx1, hy1), (hy1 - hy0) // 2)
    _cursor(d, int(hx0 + (hx1 - hx0) * 0.62), int(hy0 + (hy1 - hy0) * 0.45))
    return bottom


def _load_icon(path: str | None, size: int) -> Image.Image | None:
    if not path:
        return None
    try:
        im = Image.open(path).convert('RGBA').resize((size, size), Image.LANCZOS)
        # 按 macOS 图标的圆角裁一下(品牌图标常是直角方图)
        mask = Image.new('L', (size, size), 0)
        ImageDraw.Draw(mask).rounded_rectangle([0, 0, size - 1, size - 1],
                                               radius=int(size * 0.225), fill=255)
        im.putalpha(Image.composite(im.getchannel('A'), mask, mask))
        return im
    except Exception:
        return None


def _folder_icon(size: int) -> Image.Image:
    im = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    tab_h = size * 0.16
    d.rounded_rectangle([size * 0.06, size * 0.16, size * 0.46, size * 0.16 + tab_h * 2],
                        radius=size * 0.05, fill=(84, 166, 240))
    d.rounded_rectangle([size * 0.06, size * 0.26, size * 0.94, size * 0.86],
                        radius=size * 0.07, fill=(110, 190, 250))
    # 「A」字样示意应用程序文件夹
    f = _font('bold', size / SCALE * 0.34)
    t = 'A'
    tw = d.textlength(t, font=f)
    d.text(((size - tw) / 2, size * 0.36), t, font=f, fill=(60, 140, 220))
    return im


def render(app_name: str, out: str, icon_path: str | None = None) -> None:
    app_name = app_name.strip() or 'App'
    f_h1 = _font('bold', 34)
    f_step = _font('regular', 19)
    f_title = _font('bold', 15)
    f_body = _font('regular', 13)
    f_btn = _font('regular', 13)
    f_small = _font('regular', 14)
    f_mono = _font('regular', 15)

    H = 2600  # 先画大,最后裁掉多余
    img = Image.new('RGB', (s(W), s(H)), BG)
    d = ImageDraw.Draw(img)
    # 顶部淡蓝渐变
    for i in range(s(160)):
        t = i / s(160)
        c = tuple(int(BG_TOP[k] * (1 - t) + BG[k] * t) for k in range(3))
        d.line([(0, i), (s(W), i), ], fill=c)

    title = '安装说明'
    tw = _text_w(d, title, f_h1)
    d.text(((s(W) - tw) / 2, s(44)), title, font=f_h1, fill=TEXT)
    d.line([(s(PAD), s(112)), (s(W - PAD), s(112))], fill=(200, 202, 210), width=s(1))

    y = s(140)
    name_in_text = _ellipsize(d, app_name, f_step, s(220))

    def step(no: int, text: str):
        nonlocal y
        lines = _wrap(d, f'{no}、{text}', f_step, s(W - PAD * 2))
        for ln in lines:
            d.text((s(PAD), y), ln, font=f_step, fill=TEXT)
            y += int(f_step.size * 1.5)
        y += s(10)

    # ── 1. 拖进应用程序 ──
    step(1, f'把「{name_in_text}」拖到右边的「Applications（应用程序）」文件夹：')
    ph = s(170)
    _panel(img, d, y, ph)
    icon_sz = s(96)
    cy = y + ph // 2
    ax = s(W) // 2 - s(170)
    bx = s(W) // 2 + s(170)
    app_icon = _load_icon(icon_path, icon_sz)
    if app_icon is None:
        app_icon = Image.new('RGBA', (icon_sz, icon_sz), (0, 0, 0, 0))
        ImageDraw.Draw(app_icon).rounded_rectangle([0, 0, icon_sz - 1, icon_sz - 1],
                                                   radius=icon_sz // 4, fill=BLUE)
    img.paste(app_icon, (ax - icon_sz // 2, cy - icon_sz // 2 - s(12)), app_icon)
    folder = _folder_icon(icon_sz)
    img.paste(folder, (bx - icon_sz // 2, cy - icon_sz // 2 - s(12)), folder)
    for label, x in ((name_in_text, ax), ('Applications', bx)):
        lw = _text_w(d, label, f_body)
        d.text((x - lw / 2, cy + icon_sz // 2 - s(6)), label, font=f_body, fill=TEXT)
    # 箭头
    d.line([(ax + s(70), cy - s(12)), (bx - s(78), cy - s(12))], fill=BLUE, width=s(5))
    d.polygon([(bx - s(62), cy - s(12)), (bx - s(80), cy - s(24)), (bx - s(80), cy)],
              fill=BLUE)
    y += ph + s(34)

    # ── 2. 未打开 → 完成 ──
    q = f'“{app_name}”'
    step(2, f'打开 App 时如果提示「未打开{_ellipsize(d, q, f_step, s(240))}」，先点「完成」：')
    dlg_w = s(300)
    top = y
    # 先量高度:画到临时图上
    tmp = Image.new('RGB', (s(W), s(800)), PANEL)
    hb = _dialog(ImageDraw.Draw(tmp), s(W) // 2, s(20), dlg_w, f'未打开{q}',
                 f'Apple 无法验证{q}是否包含可能危害 Mac 安全或泄漏隐私的恶意软件。',
                 [('完成', False), ('移到废纸篓', True)], f_title, f_body, f_btn, 0)
    ph = hb - s(20) + s(40)
    _panel(img, d, top, ph)
    _dialog(d, s(W) // 2, top + s(20), dlg_w, f'未打开{q}',
            f'Apple 无法验证{q}是否包含可能危害 Mac 安全或泄漏隐私的恶意软件。',
            [('完成', False), ('移到废纸篓', True)], f_title, f_body, f_btn, 0)
    y = top + ph + s(34)

    # ── 3. 系统设置 → 仍要打开 ──
    step(3, '打开「系统设置」→「隐私与安全性」，拉到「安全性」，点「仍要打开」：')
    ph = s(200)
    top = y
    _panel(img, d, top, ph)
    # 左栏
    side_w = s(190)
    d.rounded_rectangle([s(PAD) + s(1), top + s(1), s(PAD) + side_w, top + ph - s(1)],
                        radius=s(12), fill=(238, 238, 242))
    items = [('通用', (142, 142, 147)), ('隐私与安全性', BLUE), ('桌面与程序坞', (142, 142, 147))]
    iy = top + s(34)
    for label, color in items:
        sel = label == '隐私与安全性'
        if sel:
            d.rounded_rectangle([s(PAD) + s(10), iy - s(8), s(PAD) + side_w - s(10), iy + s(26)],
                                radius=s(8), fill=BLUE)
        d.rounded_rectangle([s(PAD) + s(20), iy - s(1), s(PAD) + s(40), iy + s(19)],
                            radius=s(5), fill=(255, 255, 255) if sel else color)
        d.text((s(PAD) + s(50), iy), label, font=f_body,
               fill=(255, 255, 255) if sel else TEXT)
        iy += s(44)
    # 右侧内容
    rx0 = s(PAD) + side_w + s(20)
    rx1 = s(W - PAD) - s(20)
    d.text((rx0, top + s(22)), '安全性', font=f_title, fill=TEXT)
    row_top = top + s(56)
    d.rounded_rectangle([rx0, row_top, rx1, row_top + s(40)], radius=s(8), fill=BG)
    d.text((rx0 + s(14), row_top + s(11)), '允许以下来源的应用程序', font=f_body, fill=TEXT)
    t2 = 'App Store 与已知开发者'
    d.text((rx1 - s(14) - _text_w(d, t2, f_body), row_top + s(11)), t2, font=f_body, fill=MUTED)
    row2 = row_top + s(50)
    d.rounded_rectangle([rx0, row2, rx1, row2 + s(76)], radius=s(8), fill=BG)
    btn_w = s(92)
    bbox = (rx1 - s(14) - btn_w, row2 + s(10), rx1 - s(14), row2 + s(38))
    blocked = _ellipsize(d, f'已阻止{q}以保护 Mac。', f_body, bbox[0] - rx0 - s(28))
    d.text((rx0 + s(14), row2 + s(15)), blocked, font=f_body, fill=TEXT)
    hint = _ellipsize(d, 'Apple 无法验证其是否包含恶意软件。', f_small, rx1 - rx0 - s(28))
    d.text((rx0 + s(14), row2 + s(48)), hint, font=_font('regular', 11), fill=MUTED)
    _button(d, bbox, '仍要打开', f_btn)
    _highlight(d, bbox, (bbox[3] - bbox[1]) // 2)
    _cursor(d, int(bbox[0] + btn_w * 0.62), int(bbox[1] + s(13)))
    y = top + ph + s(34)

    # ── 4. 再点仍要打开 ──
    step(4, '在弹出的确认框里再点「仍要打开」，按提示输入开机密码：')
    top = y
    body = 'Apple 无法验证其是否包含可能危害 Mac 安全或泄漏隐私的恶意软件。除非你确定其来源可靠，否则请勿打开。'
    tmp = Image.new('RGB', (s(W), s(900)), PANEL)
    hb = _dialog(ImageDraw.Draw(tmp), s(W) // 2, s(20), dlg_w, f'打开{q}?', body,
                 [('移到废纸篓', True), ('仍要打开', False), ('完成', False)],
                 f_title, f_body, f_btn, 1)
    ph = hb - s(20) + s(40)
    _panel(img, d, top, ph)
    _dialog(d, s(W) // 2, top + s(20), dlg_w, f'打开{q}?', body,
            [('移到废纸篓', True), ('仍要打开', False), ('完成', False)],
            f_title, f_body, f_btn, 1)
    y = top + ph + s(28)

    for ln in _wrap(d, '完成后就能正常打开了，以后不会再提示。', f_step, s(W - PAD * 2)):
        d.text((s(PAD), y), ln, font=f_step, fill=TEXT)
        y += int(f_step.size * 1.5)
    y += s(20)

    # ── 常见问题 ──
    d.line([(s(PAD), y), (s(W - PAD), y)], fill=(220, 222, 228), width=s(1))
    y += s(22)
    for ln in _wrap(d, '如果提示「已损坏，无法打开」：打开「终端」，粘贴下面这行后回车，输入开机密码（输入时不显示），再打开 App。',
                    f_small, s(W - PAD * 2)):
        d.text((s(PAD), y), ln, font=f_small, fill=MUTED)
        y += int(f_small.size * 1.5)
    y += s(8)
    cmd = f'sudo xattr -dr com.apple.quarantine "/Applications/{app_name}.app"'
    cmd_lines = _wrap(d, cmd, f_mono, s(W - PAD * 2 - 32))
    box_h = len(cmd_lines) * int(f_mono.size * 1.5) + s(24)
    d.rounded_rectangle([s(PAD), y, s(W - PAD), y + box_h], radius=s(10), fill=(30, 32, 38))
    cy2 = y + s(12)
    for ln in cmd_lines:
        d.text((s(PAD) + s(16), cy2), ln, font=f_mono, fill=(230, 232, 236))
        cy2 += int(f_mono.size * 1.5)
    y += box_h + s(14)
    for ln in _wrap(d, 'macOS 14 及更早：也可以在「应用程序」里右键点 App →「打开」→「打开」。',
                    f_small, s(W - PAD * 2)):
        d.text((s(PAD), y), ln, font=f_small, fill=MUTED)
        y += int(f_small.size * 1.5)
    y += s(40)

    img.crop((0, 0, s(W), y)).save(out, optimize=True)


def main(argv: list[str]) -> int:
    if len(argv) < 3:
        print(__doc__)
        return 2
    try:
        render(argv[1], argv[2], argv[3] if len(argv) > 3 else None)
    except Exception as e:  # 调用方退回 txt
        print(f'[install-guide] 生成失败: {e}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
