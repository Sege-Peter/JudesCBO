"""
Builds the link-preview images (Open Graph, 1200x630) that Facebook, WhatsApp, X and LinkedIn show
when a judescbo.co.ke link is shared:

  images/brand/share-<page>.jpg       one per page
  admin/assets/og-story-overlay.png   transparent overlay the dashboard puts over a story's cover photo
  admin/assets/fonts/*.ttf            the brand fonts the dashboard uses to write story titles

Run after changing the wording below or the photos:   python tools/build-og.py
Needs: pip install pillow fonttools brotli
"""
import io
import os

from fontTools.ttLib import TTFont
from fontTools.varLib import instancer
from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
W, H = 1200, 630
NAVY = (11, 29, 53)
RED = (214, 48, 49)
MINT = (154, 230, 180)
PAD = 64

# page key: (photo, label, title, subtitle)
PAGES = {
    'home': ('images/hero-4.webp', 'Oyugis, Homa Bay', 'Empowering communities in Homa Bay County',
             'Ending SGBV and advancing youth health, mental health and disability inclusion since 2018.'),
    'default': ('images/hero-1.webp', 'Judes CBO', 'Judes Community Based Organization',
                'A grassroots organization in Oyugis, Homa Bay County, Kenya.'),
    'about': ('images/hero-2.webp', 'About us', 'A grassroots force for dignity and inclusion',
              'Our story, mission, vision and values, inspired by St. Jude.'),
    'programs': ('images/hero-5.webp', 'Our programs', 'Programs that protect, empower and uplift',
                 'SGBV response · Youth health · Mental health · Disability inclusion'),
    'events': ('images/hero-4.webp', 'Events', 'Join us in the community',
               'Outreaches, health camps, trainings and community dialogues across Homa Bay County.'),
    'gallery': ('images/hero-6.webp', 'Gallery', 'Our work in pictures',
                'Outreaches, community dialogues and events across Homa Bay County.'),
    'stories': ('images/hero-7.webp', 'Stories & news', 'Stories and news from Homa Bay County',
                'Updates from our work with young people, families and partners.'),
    'team': ('images/hero-3.webp', 'Our team', 'The people behind Judes CBO',
             'Health professionals, disability advocates and community leaders.'),
    'donate': ('images/hero-1.webp', 'Donate', 'Your gift changes lives in Homa Bay',
               'Give via M-Pesa to support survivors, young people and children with disabilities.'),
    'contact': ('images/hero-3.webp', 'Contact us', "Let's talk",
                'Call or WhatsApp +254 712 109310, or send us a message online.'),
}


def static_font(src: str, weight: int, out: str) -> str:
    """Turns the self-hosted variable woff2 into a static TTF at one weight (Pillow and PHP need TTF)."""
    path = os.path.join(ROOT, 'admin', 'assets', 'fonts', out)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    font = TTFont(os.path.join(ROOT, 'fonts', src))
    font.flavor = None
    inst = instancer.instantiateVariableFont(font, {'wght': weight})
    inst.save(path)
    return path


def fonts():
    return {
        'black': static_font('montserrat-latin.woff2', 800, 'Montserrat-ExtraBold.ttf'),
        'bold': static_font('montserrat-latin.woff2', 700, 'Montserrat-Bold.ttf'),
        'body': static_font('raleway-latin.woff2', 600, 'Raleway-SemiBold.ttf'),
    }


def wrap(draw, text, font, width):
    lines, line = [], ''
    for word in text.split():
        test = (line + ' ' + word).strip()
        if draw.textlength(test, font=font) <= width or not line:
            line = test
        else:
            lines.append(line)
            line = word
    if line:
        lines.append(line)
    return lines


def cover(path):
    im = Image.open(os.path.join(ROOT, path)).convert('RGB')
    r = max(W / im.width, H / im.height)
    im = im.resize((round(im.width * r), round(im.height * r)), Image.LANCZOS)
    left, top = (im.width - W) // 2, (im.height - H) // 2
    return im.crop((left, top, left + W, top + H))


def shade() -> Image.Image:
    """Navy gradient: solid on the left where the text sits, lighter on the right so the photo shows."""
    g = Image.new('RGBA', (W, H))
    px = g.load()
    for x in range(W):
        t = x / W
        a = int(245 - 150 * (t ** 1.35))          # 245 -> ~95
        for y in range(H):
            b = max(0, (y - H * 0.62) / (H * 0.38)) * 60  # a little darker at the bottom
            px[x, y] = (*NAVY, min(255, int(a + b)))
    return g


def logo_tile(size=88):
    tile = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    d = ImageDraw.Draw(tile)
    d.rounded_rectangle((0, 0, size - 1, size - 1), radius=22, fill=(255, 255, 255, 255))
    logo = Image.open(os.path.join(ROOT, 'jude-logo.png')).convert('RGBA')
    logo = logo.crop(logo.getbbox())
    logo.thumbnail((size - 16, size - 16), Image.LANCZOS)
    tile.alpha_composite(logo, ((size - logo.width) // 2, (size - logo.height) // 2))
    return tile


def chrome(F, label: str) -> Image.Image:
    """Everything except the photo, title and subtitle: brand header, label, footer, accent bar."""
    layer = shade()
    d = ImageDraw.Draw(layer)
    # Soft shadow under the logo tile
    sh = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(sh).rounded_rectangle((PAD + 2, 52, PAD + 90, 140), radius=22, fill=(0, 0, 0, 110))
    layer.alpha_composite(sh.filter(ImageFilter.GaussianBlur(10)))
    layer.alpha_composite(logo_tile(), (PAD, 46))
    d.text((PAD + 108, 58), 'JUDES CBO', font=ImageFont.truetype(F['black'], 34), fill='white')
    d.text((PAD + 110, 102), 'EMPOWERMENT FOR DEVELOPMENT', font=ImageFont.truetype(F['bold'], 15), fill=MINT)
    # Label pill
    lf = ImageFont.truetype(F['bold'], 19)
    text = label.upper()
    tw = d.textlength(text, font=lf) + 2 * len(text) * 0.6
    d.rounded_rectangle((PAD, 196, PAD + tw + 36, 236), radius=20, fill=RED)
    x = PAD + 18
    for ch in text:  # letter spacing
        d.text((x, 205), ch, font=lf, fill='white')
        x += d.textlength(ch, font=lf) + 1.2
    # Footer
    d.line((PAD, H - 82, W - PAD, H - 82), fill=(255, 255, 255, 50), width=1)
    d.text((PAD, H - 64), 'judescbo.co.ke', font=ImageFont.truetype(F['black'], 24), fill='white')
    loc = 'Oyugis · Homa Bay County, Kenya'
    lfnt = ImageFont.truetype(F['body'], 21)
    d.text((W - PAD - d.textlength(loc, font=lfnt), H - 62), loc, font=lfnt, fill=(255, 255, 255, 215))
    # Accent bar: green to mint to amber
    for x in range(W):
        t = x / (W - 1)
        if t < 0.6:
            c = tuple(int(a + (b - a) * (t / 0.6)) for a, b in zip((45, 80, 22), (104, 211, 145)))
        else:
            c = tuple(int(a + (b - a) * ((t - 0.6) / 0.4)) for a, b in zip((104, 211, 145), (246, 173, 85)))
        d.line((x, H - 10, x, H), fill=(*c, 255))
    return layer


def title_block(d, F, title, subtitle, y=262):
    size = 62 if len(title) <= 34 else 54
    tf = ImageFont.truetype(F['black'], size)
    lines = wrap(d, title, tf, 780)[:3]
    for line in lines:
        d.text((PAD, y), line, font=tf, fill='white')
        y += int(size * 1.12)
    if subtitle:
        y += 14
        sf = ImageFont.truetype(F['body'], 27)
        for line in wrap(d, subtitle, sf, 760)[:2]:
            d.text((PAD, y), line, font=sf, fill=(255, 255, 255, 225))
            y += 38


def main():
    F = fonts()
    out_dir = os.path.join(ROOT, 'images', 'brand')
    for key, (photo, label, title, subtitle) in PAGES.items():
        card = cover(photo).convert('RGBA')
        card.alpha_composite(chrome(F, label))
        title_block(ImageDraw.Draw(card), F, title, subtitle)
        path = os.path.join(out_dir, f'share-{key}.jpg')
        card.convert('RGB').save(path, 'JPEG', quality=84, optimize=True, progressive=True)
        print(f'share-{key}.jpg  {os.path.getsize(path) // 1024} KB')
    # Story overlay: the dashboard adds the cover photo underneath and the title on top (at y=262)
    overlay = chrome(F, 'Story')
    overlay.save(os.path.join(ROOT, 'admin', 'assets', 'og-story-overlay.png'), optimize=True)
    print('og-story-overlay.png')


if __name__ == '__main__':
    main()
