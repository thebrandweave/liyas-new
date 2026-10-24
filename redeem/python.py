import qrcode

qr = qrcode.QRCode(
    version=1,
    error_correction=qrcode.constants.ERROR_CORRECT_H,
    box_size=12,
    border=4,
)

qr.add_data("https://liyasinternation.com/join")
qr.make(fit=True)

img = qr.make_image(fill_color="black", back_color="white")
img.save("liyasinternation_join_qr_hd.png")