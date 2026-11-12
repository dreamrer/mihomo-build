"""mac_install_guide 单测:python3 -m unittest scripts/test_mac_install_guide.py(需 macOS 自带中文字体 + Pillow)。"""
import os
import sys
import tempfile
import unittest

from PIL import Image

sys.path.insert(0, os.path.dirname(__file__))
import mac_install_guide as g  # noqa: E402


def _has_font():
    return any(os.path.exists(p) for p, _ in g._FONT_CANDIDATES['regular'])


@unittest.skipUnless(_has_font(), '没有 macOS 中文字体')
class GuideTest(unittest.TestCase):
    def _render(self, name, icon=None):
        out = os.path.join(tempfile.mkdtemp(), 'g.png')
        self.assertEqual(g.main(['x', name, out] + ([icon] if icon else [])), 0)
        im = Image.open(out)
        self.assertEqual(im.size[0], g.W * g.SCALE)
        self.assertGreater(im.size[1], 2000)
        return im

    def test_names(self):
        for name in ['快连', 'X', 'Super Long Brand Name Accelerator Pro', '云"引号"加速 & <x>']:
            self._render(name)

    def test_bad_icon_falls_back(self):
        self._render('快连', '/nonexistent.png')

    def test_icon_rounded(self):
        d = tempfile.mkdtemp()
        icon = os.path.join(d, 'i.png')
        Image.new('RGBA', (1024, 1024), (255, 0, 0, 255)).save(icon)
        out = os.path.join(d, 'g.png')
        g.render('快连', out, icon)
        im = Image.open(out).convert('RGB')
        # 图标左上角被圆角裁掉,露出面板底色而不是红色
        x = (g.W // 2 - 170 - 48) * g.SCALE + 2
        y_top = None
        for y in range(0, im.size[1] // 3):
            if im.getpixel((g.W // 2 * g.SCALE - 170 * g.SCALE, y)) == (255, 0, 0):
                y_top = y
                break
        self.assertIsNotNone(y_top, '没画出图标')
        self.assertNotEqual(im.getpixel((x, y_top + 2)), (255, 0, 0), '图标没裁圆角')

    def test_no_fonts_returns_nonzero(self):
        saved = g._FONT_CANDIDATES
        g._FONT_CANDIDATES = {'regular': [('/nope.ttc', 0)], 'bold': [('/nope.ttc', 0)]}
        try:
            out = os.path.join(tempfile.mkdtemp(), 'g.png')
            self.assertNotEqual(g.main(['x', '快连', out]), 0)
            self.assertFalse(os.path.exists(out))
        finally:
            g._FONT_CANDIDATES = saved


if __name__ == '__main__':
    unittest.main()
