#!/usr/bin/env python3
"""
smart_crop.py — recorte inteligente centrado em rosto usando MediaPipe.
Fallback para recorte central se MediaPipe não estiver instalado ou sem rosto.

Uso: python smart_crop.py <entrada> <saída> [proporcao]
  proporcao: largura/altura (ex: 0.75 para 3:4). Padrão: 0.75
"""

import sys
import os

def center_crop(img, aspect):
    w, h = img.size
    target_w = w
    target_h = int(w / aspect)
    if target_h > h:
        target_h = h
        target_w = int(h * aspect)
    x = (w - target_w) // 2
    y = (h - target_h) // 2
    return img.crop((x, y, x + target_w, y + target_h))


def face_crop(img, aspect):
    try:
        import mediapipe as mp
        import numpy as np

        mp_face = mp.solutions.face_detection
        img_rgb = img.convert('RGB')
        img_np  = np.array(img_rgb)
        w, h    = img.size

        with mp_face.FaceDetection(model_selection=1, min_detection_confidence=0.4) as det:
            results = det.process(img_np)

        if not results.detections:
            return center_crop(img, aspect)

        # Melhor detecção (maior score)
        best = max(results.detections, key=lambda d: d.score[0])
        bb   = best.location_data.relative_bounding_box

        fx = int(bb.xmin  * w)
        fy = int(bb.ymin  * h)
        fw = int(bb.width  * w)
        fh = int(bb.height * h)

        # Centro levemente acima do rosto (região dos olhos/cílios)
        cx = fx + fw // 2
        cy = fy + int(fh * 0.38)

        # Crop com padding generoso ao redor do rosto
        crop_w = min(int(fw * 2.8), w)
        crop_h = int(crop_w / aspect)
        if crop_h > h:
            crop_h = h
            crop_w = int(crop_h * aspect)

        x0 = max(0, min(cx - crop_w // 2, w - crop_w))
        y0 = max(0, min(cy - crop_h // 2, h - crop_h))

        return img.crop((x0, y0, x0 + crop_w, y0 + crop_h))

    except ImportError:
        # MediaPipe não instalado — fallback
        return center_crop(img, aspect)
    except Exception as e:
        print(f'[warn] face_crop: {e}', file=sys.stderr)
        return center_crop(img, aspect)


def main():
    if len(sys.argv) < 3:
        print('Uso: smart_crop.py <entrada> <saida> [proporcao]', file=sys.stderr)
        sys.exit(1)

    input_path  = sys.argv[1]
    output_path = sys.argv[2]
    aspect      = float(sys.argv[3]) if len(sys.argv) > 3 else 0.75

    if not os.path.isfile(input_path):
        print(f'Arquivo não encontrado: {input_path}', file=sys.stderr)
        sys.exit(1)

    try:
        from PIL import Image, ImageOps

        img = Image.open(input_path)
        img = ImageOps.exif_transpose(img)  # corrige orientação EXIF
        img = img.convert('RGB')

        cropped = face_crop(img, aspect)

        # Redimensiona para máx. 1600 px de largura para consistência
        MAX_W = 1600
        if cropped.width > MAX_W:
            ratio   = MAX_W / cropped.width
            cropped = cropped.resize(
                (MAX_W, int(cropped.height * ratio)),
                Image.LANCZOS
            )

        cropped.save(output_path, 'JPEG', quality=92, optimize=True)
        print('ok')
        sys.exit(0)

    except ImportError:
        print('Pillow não instalado. Execute: pip install Pillow', file=sys.stderr)
        sys.exit(2)
    except Exception as e:
        print(f'Erro: {e}', file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
