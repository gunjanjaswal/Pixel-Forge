=== Pixel Forge ===
Contributors: gunjanjaswal
Donate link: https://ko-fi.com/gunjanjaswal
Tags: webp, avif, images, performance, optimization
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bulk-convert your media library to WebP and AVIF with a live progress screen, then roll every generated file back with one click.

== Description ==

Pixel Forge converts the images already in your media library to WebP and AVIF, the modern formats that are far smaller than JPEG and PNG at the same quality. It works through your library in small batches with a progress bar you can watch, and it keeps every original file exactly as it is.

When you want the next-gen files gone, one click removes all of them and leaves your originals untouched. Nothing about the process is destructive.

**What makes it different**

Most converters in this space hand the work to a paid external service and your images leave your server. Pixel Forge does the opposite: everything runs locally through WordPress' own image engine, with no third-party service, no account, no API key, and no per-image credits. Nothing about an image ever leaves your site.

It is also completely reversible. Originals are never modified or replaced, every generated file is recorded, and one click removes all of them. And you are not limited to a single bulk run: a Next-gen column in the Media Library shows each image's status and real savings, with Convert and Remove right there on the row.

**What you get**

* Bulk conversion of your whole library to WebP, AVIF, or both.
* A live progress screen that converts in small batches, so large libraries do not time out.
* A Next-gen column in the Media Library with per-image savings and one-click Convert or Remove.
* A quality control, and automatic detection of which formats your server can create.
* Optional front-end delivery: images are served inside a `<picture>` tag with the original as a fallback, so a missing file can never break a page.
* One-click rollback that deletes every generated file and keeps your originals.
* Fully local. No external services, no account, no data leaving your site.

**How conversion works**

Each original keeps its place. Pixel Forge writes the new file next to it with the format added to the name, for example `photo.jpg` gets a `photo.jpg.webp` beside it. The full-size image and every thumbnail size are converted, and each generated file is recorded so rollback can find and remove it.

**How serving works**

Serving is off until you turn it on. When it is on, an image that has a matching WebP or AVIF file is output as a `<picture>` element with the next-gen sources first and the original `<img>` last. The browser downloads the first format it understands and falls back to the original otherwise.

== Installation ==

1. Upload the `pixel-forge` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate the plugin.
3. Go to Media > Pixel Forge.
4. Choose your formats and quality, save, then click Start conversion.

Your server needs GD or Imagick with WebP support, and with AVIF support for AVIF (WordPress 6.5 or newer). The settings screen shows which formats are available.

== Frequently Asked Questions ==

= Does it change or delete my original images? =

No. Originals are never modified. New files are written alongside them, and rollback only removes those new files.

= Where do the converted files go? =

Next to each original, with the format appended, such as `image.jpg.webp` and `image.jpg.avif`. The full size and every thumbnail size are converted.

= Will my pages break if a next-gen file is missing? =

No. Serving uses a `<picture>` element with the original `<img>` as the last fallback, so the browser always has the original to fall back to.

= Why is AVIF slower? =

AVIF encoding is more CPU-intensive than WebP. That is why conversion runs in small batches. It is normal for AVIF to take longer than WebP.

= How do I undo everything? =

Open Media > Pixel Forge and use the Rollback section. It deletes every file the plugin generated and leaves your originals in place.

== Screenshots ==

1. The Pixel Forge screen: status cards, settings, and the conversion progress bar.

== Changelog ==

= 1.0.0 =
* First release: fully local bulk WebP and AVIF conversion with a batched progress screen, a Media Library column with per-image Convert/Remove, quality control, optional `<picture>` serving, and one-click rollback.
