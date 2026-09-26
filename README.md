# Sunucu

Mehmet Ali'nin VPS'inde çalışan uygulamalar ve onları yayınlayan **Yayın Paneli**.

## Nasıl çalışır

`uygulamalar/` altındaki her klasör sunucuda bir adrese karşılık gelir:

| Klasör | Adres |
|---|---|
| `uygulamalar/yorumlar` | `https://SUNUCU/yorumlar/` — Facebook sayfa yorumlarını listeler, çekiliş yapar |

Bu repoya yapılan her gönderim, Yayın Paneli tarafından 5 dakika içinde (webhook varsa hemen) sunucuya yayınlanır.
Uygulamaların `veri/` klasörü sunucuda kalır; ayarlar ve anahtarlar bu repoya **hiçbir zaman** girmez.

## Sunucuya ilk kurulum

Ubuntu 22.04/24.04 sunucuda root olarak:

```bash
curl -fsSL https://raw.githubusercontent.com/malibuyan/sunucu/main/kur.sh -o /tmp/kur.sh && bash /tmp/kur.sh
```

Kurulum sonunda panel adresi ve şifresi ekrana yazılır (`cat /root/yayin-bilgi.txt` ile tekrar görülebilir).

## Panele uygulama bağlama

Panel → **GitHub** → Repo bağla:

- Uygulama adı: `yorumlar`
- Repo adresi: `https://github.com/malibuyan/sunucu`
- Dal: `main`
- Klasör: `uygulamalar/yorumlar`

## Yayın Paneli'ni geliştirme

Kaynak `yayin-paneli/` altında. Değişiklikten sonra `bash yayin-paneli/build.sh` çalıştırıp
üretilen `yayin-paneli/dist/kur.sh` dosyasını kökteki `kur.sh` üzerine kopyala.
