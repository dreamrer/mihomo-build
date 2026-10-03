"""brand_logo 单测:python3 -m unittest scripts/test_brand_logo.py(只依赖 Pillow)。"""
import os
import sys
import unittest

from PIL import Image, ImageDraw

sys.path.insert(0, os.path.dirname(__file__))
import brand_logo as b  # noqa: E402

BLUE_TOP, BLUE_BOTTOM = (40, 180, 255), (10, 50, 220)


def _gradient_tile(side=1024, margin=24, radius=220, bg=(253, 253, 253, 255)):
    """白底(或透明底)上的渐变圆角色块,中间一个白色图形(常见的"圆角色块"型 logo)。"""
    im = Image.new('RGBA', (side, side), bg)
    tile = Image.new('RGBA', (side - 2 * margin,) * 2)
    px = tile.load()
    n = tile.size[0]
    for y in range(n):
        t = y / (n - 1)
        c = tuple(int(BLUE_TOP[i] * (1 - t) + BLUE_BOTTOM[i] * t) for i in range(3))
        for x in range(n):
            px[x, y] = c + (255,)
    ImageDraw.Draw(tile).polygon([(n * .3, n * .25), (n * .7, n * .5), (n * .3, n * .75)],
                                 fill=(255, 255, 255, 255))
    m = Image.new('L', tile.size, 0)
    ImageDraw.Draw(m).rounded_rectangle((0, 0, n - 1, n - 1), radius=radius, fill=255)
    im.paste(tile, (margin, margin), m)
    return im


def _is_whiteish(p):
    return p[0] > 225 and p[1] > 225 and p[2] > 225


class NormalizeTest(unittest.TestCase):
    def assertOpaqueSquare(self, sq):
        self.assertEqual(sq.size, (1024, 1024))
        self.assertEqual(sq.getchannel('A').getextrema(), (255, 255))

    def test_tile_on_white_becomes_fullbleed(self):
        sq, info = b.normalize(_gradient_tile())
        self.assertEqual(info['kind'], 'tile')
        self.assertOpaqueSquare(sq)
        for xy in [(2, 2), (1021, 2), (2, 1021), (1021, 1021)]:
            self.assertFalse(_is_whiteish(sq.getpixel(xy)), f'角 {xy} 还是白的')

    def test_tile_on_transparent_becomes_fullbleed(self):
        sq, info = b.normalize(_gradient_tile(bg=(0, 0, 0, 0)))
        self.assertEqual(info['kind'], 'tile')
        self.assertOpaqueSquare(sq)
        self.assertFalse(_is_whiteish(sq.getpixel((2, 2))))

    def test_android_icon_of_tile_has_no_white_ring(self):
        """真机反馈:圆角色块型 logo 在安卓桌面上多一圈白边。可见区(画布中间 72/108)四边中点不能是白的。"""
        sq, _ = b.normalize(_gradient_tile())
        fg = b.android_adaptive_foreground(sq, 432)
        v = int(432 * 72 / 108)
        o = (432 - v) // 2
        for xy in [(o + 2, 216), (o + v - 3, 216), (216, o + 2), (216, o + v - 3)]:
            self.assertFalse(_is_whiteish(fg.getpixel(xy)), f'可见区边缘 {xy} 是白的')
        self.assertNotEqual(b.android_background_hex(sq), '#FFFFFF')

    def test_fullbleed_kept(self):
        im = Image.new('RGBA', (1024, 1024))
        px = im.load()
        for y in range(1024):
            for x in range(1024):
                px[x, y] = (x // 4, 80, 255 - y // 4, 255)
        sq, info = b.normalize(im)
        self.assertEqual(info['kind'], 'fullbleed')
        self.assertOpaqueSquare(sq)

    def test_graphic_on_solid_color_kept(self):
        im = Image.new('RGBA', (1024, 1024), (28, 31, 36, 255))
        ImageDraw.Draw(im).ellipse((300, 300, 724, 724), fill=(255, 140, 0, 255))
        sq, info = b.normalize(im)
        self.assertEqual(info['kind'], 'graphic_on_color')
        self.assertEqual(sq.getpixel((5, 5))[:3], (28, 31, 36))

    def test_transparent_graphic_gets_background_and_safe_size(self):
        im = Image.new('RGBA', (1024, 1024), (0, 0, 0, 0))
        ImageDraw.Draw(im).rectangle((0, 400, 1023, 620), fill=(16, 185, 129, 255))  # 贴边的横条
        sq, info = b.normalize(im)
        self.assertEqual(info['kind'], 'graphic_transparent')
        self.assertOpaqueSquare(sq)
        m = Image.new('L', sq.size, 0)
        mp, px = m.load(), sq.load()
        for y in range(0, 1024, 4):
            for x in range(0, 1024, 4):
                if px[x, y][:3] != info['bg']:
                    mp[x, y] = 255
        bb = m.getbbox()
        self.assertLessEqual((bb[2] - bb[0]) / 1024, b.GRAPHIC_MAX + 0.02, '图形没缩进安全区')

    def test_background_contrast(self):
        im = Image.new('RGBA', (1024, 1024), (0, 0, 0, 0))
        ImageDraw.Draw(im).ellipse((300, 300, 724, 724), fill=(124, 58, 237, 255))
        self.assertEqual(b.normalize(im, '7C3AED')[1]['bg'], (255, 255, 255), '同色品牌底会让图形消失')
        self.assertEqual(b.normalize(im, '#FFE600')[1]['bg'], (255, 230, 0))
        white = Image.new('RGBA', (1024, 1024), (0, 0, 0, 0))
        ImageDraw.Draw(white).ellipse((300, 300, 724, 724), fill=(255, 255, 255, 255))
        self.assertNotEqual(b.normalize(white)[1]['bg'], (255, 255, 255), '白图形不能垫白底')

    def test_non_square_and_small_inputs(self):
        wide = Image.new('RGBA', (1400, 700), (0, 0, 0, 0))
        ImageDraw.Draw(wide).rounded_rectangle((100, 150, 1300, 550), radius=80, fill=(16, 185, 129, 255))
        self.assertOpaqueSquare(b.normalize(wide)[0])
        tiny = Image.new('RGBA', (48, 48), (0, 0, 0, 0))
        ImageDraw.Draw(tiny).ellipse((8, 8, 40, 40), fill=(200, 0, 0, 255))
        self.assertOpaqueSquare(b.normalize(tiny)[0])
        with self.assertRaises(ValueError):   # 全透明 = 没有图形,让打包失败而不是发纯色图标
            b.normalize(Image.new('RGBA', (1024, 1024), (0, 0, 0, 0)))

    def test_macos_icon_template(self):
        sq, _ = b.normalize(_gradient_tile())
        mac = b.macos_icon(sq, 1024)
        self.assertEqual(mac.getpixel((20, 20))[3], 0, 'macOS 图标四周应透明留白')
        self.assertEqual(mac.getpixel((512, 512))[3], 255)


class AuditCasesTest(unittest.TestCase):
    """用合成图复现的边界情况。"""

    def test_cutout_letter_survives(self):
        im = Image.new('RGBA', (1024, 1024), (0, 0, 0, 0))
        tile = Image.new('RGBA', (1024, 1024), (30, 120, 240, 255))
        m = Image.new('L', (1024, 1024), 0)
        ImageDraw.Draw(m).rounded_rectangle((0, 0, 1023, 1023), radius=220, fill=255)
        im.paste(tile, (0, 0), m)
        ImageDraw.Draw(im).rectangle((420, 300, 600, 720), fill=(0, 0, 0, 0))  # 镂空的"字母"
        sq, info = b.normalize(im)
        self.assertEqual(info['kind'], 'tile')
        c = sq.getpixel((512, 512))
        self.assertGreater(b.contrast(c[:3], (30, 120, 240)), 2.5, f'镂空处被填成色块同色: {c}')

    def test_lime_tile_not_erased(self):
        im = Image.new('RGBA', (1024, 1024), (0, 0, 0, 0))
        ImageDraw.Draw(im).rounded_rectangle((20, 20, 1003, 1003), radius=200, fill=(0, 255, 0, 255))
        sq, info = b.normalize(im)
        self.assertEqual(info['kind'], 'tile')
        self.assertEqual(sq.getpixel((512, 512))[:3], (0, 255, 0))

    def test_magenta_inside_tile(self):
        im = _gradient_tile()
        ImageDraw.Draw(im).ellipse((450, 450, 574, 574), fill=(255, 0, 254, 255))
        self.assertEqual(b.normalize(im)[1]['kind'], 'tile')

    def test_fullbleed_circle_no_white_ring(self):
        im = Image.new('RGBA', (1024, 1024), (0, 0, 0, 0))
        ImageDraw.Draw(im).ellipse((0, 0, 1023, 1023), fill=(230, 60, 60, 255))
        sq, info = b.normalize(im)
        self.assertEqual(info['kind'], 'tile')
        self.assertFalse(_is_whiteish(sq.getpixel((3, 3))))

    def test_shadow_not_part_of_tile(self):
        im = Image.new('RGBA', (1024, 1024), (0, 0, 0, 0))
        ImageDraw.Draw(im).rounded_rectangle((60, 80, 1000, 1020), radius=200, fill=(0, 0, 0, 90))  # 投影
        ImageDraw.Draw(im).rounded_rectangle((24, 24, 960, 960), radius=200, fill=(30, 120, 240, 255))
        sq, info = b.normalize(im)
        self.assertEqual(info['kind'], 'tile')
        self.assertLess(max(abs(a - c) for a, c in zip(info['edge'], (30, 120, 240))), 20, info['edge'])

    def test_small_graphic_on_white_scaled_up(self):
        im = Image.new('RGBA', (1024, 1024), (255, 255, 255, 255))
        ImageDraw.Draw(im).ellipse((412, 412, 612, 612), fill=(99, 102, 241, 255))  # 只占 20%
        sq, info = b.normalize(im)
        self.assertEqual(info['kind'], 'graphic_on_color')
        self.assertNotEqual(sq.getpixel((512, 512 - 205))[:3], (255, 255, 255))

    def test_one_pixel_wide(self):
        im = Image.new('RGBA', (1, 5))
        for y, c in enumerate([(255, 0, 0, 255), (0, 255, 0, 255), (0, 0, 255, 255),
                               (9, 9, 9, 255), (200, 200, 0, 255)]):
            im.putpixel((0, y), c)
        self.assertEqual(b.normalize(im)[0].size, (1024, 1024))

    def test_windows_icon_rounded(self):
        sq, _ = b.normalize(_gradient_tile())
        w = b.windows_icon(sq, 256)
        self.assertEqual(w.getpixel((0, 0))[3], 0)
        self.assertEqual(w.getpixel((128, 128))[3], 255)


if __name__ == '__main__':
    unittest.main()
