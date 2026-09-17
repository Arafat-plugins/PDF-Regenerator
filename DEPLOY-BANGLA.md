# PDF Regenerator — website-e bosano (cPanel)

Ei package-e jhamela komanor shob kichu age thekei kora ache:

- **Upload limit, memory ar time:** nije theke set hoy (`.user.ini` ar `.htaccess`). MultiPHP INI Editor-e kichu korte hobe na.
- **Login:** PC-te je username ar password diye login koro, server-eo shetai cholbe. Terminal-e user banate hobe na.
- **Purono file cleanup:** protidin nije theke hoy. Cron Job lagbe na.
- **Poppler (pdftotext):** server-e thakle nije theke khuje nei. Na thakle PHP reader diye kaj chalay.
- **SSL (AutoSSL / Let's Encrypt):** kono badha pabe na.
- **Kichu bhul thakle:** jemon permission, PHP extension ba `vendor/` folder na thakle, page-e shoja bhashay lekha ashbe ki thik korte hobe.

> **Shotorkota:** jei domain-er `public_html`-e WordPress ache, shekhane shorashori root-e boshabe na. Duita site-er `.htaccess` ar `index.php` eke oporer shathe dhakka khabe. Tar bodole **subdomain** bebohar koro (Upay 1).

---

## Upay 1 — Subdomain (recommended), jemon `pdf.goodtechies.com`

1. **cPanel → File Manager** kholo, home folder-e jao (`/home/USERNAME/`). Eta `public_html`-er **baire**.
2. **Upload** diye `pdf-regenerator-deploy.zip` upload koro. Tarpor zip-e right-click kore **Extract** koro. Ekta `pdf-regenerator` folder toiri hobe.
3. **cPanel → Domains → Create A New Domain** e jao:
   - Domain: `pdf.goodtechies.com`
   - "Share document root" option-er tick **tule dao**
   - Document Root: `pdf-regenerator/public`
   - **Submit** chapo.
4. **cPanel → MultiPHP Manager** e `pdf.goodtechies.com` select koro, PHP **8.1 ba tar upore** (8.3 bhalo) dao, tarpor **Apply** chapo.
5. **cPanel → SSL/TLS Status** e **Run AutoSSL** chapo (https-er jonno).
6. Browser-e `https://pdf.goodtechies.com` kholo, tarpor PC-r username ar password diye login koro.
7. **Settings → System check** kholo. Shob kichu sobuj thakle kaj shesh.

## Upay 2 — Kono khali domain-er root-e (shekhane onno kono site nei)

1. **File Manager → `public_html`** e jao. Folder-ti khali kina dekhe nao.
2. Zip upload kore **Extract** koro. Tarpor `pdf-regenerator` folder-er **bhitorer shob kichu** (lukano file-o) `public_html`-e **Move** koro.
   - Lukano file dekhte: File Manager-er **Settings → Show Hidden Files** chalu koro. `.htaccess` ar `.user.ini` file duita obosshoi move korte hobe.
3. Er porer kaj Upay 1-er **4 theke 7 number step**-er moto.

---

## Kichu bhul hole

| Ki dekhcho | Ki korbe |
| --- | --- |
| "one more setup step" page | Page-e jei kaj-er kotha lekha ache sheta koro. Jemon extension chalu korte **Select PHP Version → Extensions**-e `mbstring`, `gd`, `intl`, `fileinfo`-te tick dao. |
| "500 Internal Server Error" | MultiPHP Manager-e PHP 8.1+ select kora ache kina dekho. |
| Login hoy na | Password PC-r moto-i. Bhule gele Terminal-e `cd ~/pdf-regenerator && php bin/create-user.php` diye notun user banao. |
| Upload-e "too large" | Seema 10 MB. `.user.ini` kaj shuru korte 5 minit porjonto lagte pare. |
| Lukano file-er kotha | `config/`, `storage/`, `app/` URL diye kholar cheshta korle 403/404 ashbe. Eta-i thik, er mane egulo shurokkhito. |

## Porer bar update korar shomoy

- Notun zip upload korle **`config/users.csv` ar `storage/` folder replace korbe na**. Tahole server-e password ar tomar shob file thik thakbe.
- Password bodlate chaile **Settings → Change password** bebohar koro.

## Chaile (optional)

- Login page-e dekhano admin email bodlate `config/app.php`-er `admin_email` edit koro.
- Aro user lagle Terminal-e `php bin/create-user.php` chalao.
