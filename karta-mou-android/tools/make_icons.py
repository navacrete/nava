#!/usr/bin/env python3
"""Generate launcher icons (teal rounded square with a white card + check)."""
import os, sys, zlib, struct

root = sys.argv[1] if len(sys.argv) > 1 else '.'


def png(path, size):
    try:
        from PIL import Image, ImageDraw
        im = Image.new("RGBA", (size, size), (0, 0, 0, 0))
        d = ImageDraw.Draw(im)
        d.rounded_rectangle([0, 0, size - 1, size - 1], radius=size // 5, fill=(4, 158, 156, 255))
        m = size // 6
        d.rounded_rectangle([m, size // 3, size - m, size - size // 3], radius=size // 16, fill=(255, 255, 255, 255))
        w = max(2, size // 28)
        d.line([(size * 0.36, size * 0.52), (size * 0.46, size * 0.62), (size * 0.66, size * 0.40)], fill=(4, 158, 156, 255), width=w)
        im.save(path)
        return
    except Exception:
        pass
    raw = b''.join(b'\x00' + bytes([4, 158, 156, 255]) * size for _ in range(size))

    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)

    data = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', size, size, 8, 6, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw, 9)) + chunk(b'IEND', b'')
    open(path, 'wb').write(data)


for d, s in [('mdpi', 48), ('hdpi', 72), ('xhdpi', 96), ('xxhdpi', 144), ('xxxhdpi', 192)]:
    folder = os.path.join(root, 'res', f'mipmap-{d}')
    os.makedirs(folder, exist_ok=True)
    png(os.path.join(folder, 'ic_launcher.png'), s)
print('icons ok')
