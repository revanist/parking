import os
import re
import urllib.request

base_dir = r"c:\xampp\htdocs\parking"
assets_dir = os.path.join(base_dir, "assets")

dirs = [
    "js",
    "css",
    "webfonts",
    "fonts"
]
for d in dirs:
    os.makedirs(os.path.join(assets_dir, d), exist_ok=True)

def download(url, dest):
    print(f"Downloading {url} to {dest}")
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'})
    with urllib.request.urlopen(req) as response:
        with open(dest, 'wb') as f:
            f.write(response.read())

# 1. Tailwind
download("https://cdn.tailwindcss.com", os.path.join(assets_dir, "js", "tailwindcss.js"))

# 2. Lucide
download("https://unpkg.com/lucide@latest", os.path.join(assets_dir, "js", "lucide.js"))

# 3. FontAwesome 
fa_css_url = "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
fa_css_path = os.path.join(assets_dir, "css", "fontawesome.min.css")
download(fa_css_url, fa_css_path)

fa_fonts = [
    "fa-brands-400.woff2",
    "fa-brands-400.ttf",
    "fa-regular-400.woff2",
    "fa-regular-400.ttf",
    "fa-solid-900.woff2",
    "fa-solid-900.ttf",
    "fa-v4compatibility.woff2",
    "fa-v4compatibility.ttf"
]
fa_base_url = "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/webfonts/"
for f in fa_fonts:
    try:
        download(fa_base_url + f, os.path.join(assets_dir, "webfonts", f))
    except Exception as e:
        print(f"Failed to download {f}: {e}")

# 4. Google Fonts
gfonts_url = "https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap"

def download_gfonts(url, css_filename):
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36'})
    with urllib.request.urlopen(req) as response:
        css_content = response.read().decode('utf-8')
    
    urls = re.findall(r'url\((https://[^)]+)\)', css_content)
    for u in urls:
        filename = u.split('/')[-1]
        try:
            download(u, os.path.join(assets_dir, "fonts", filename))
            css_content = css_content.replace(u, f"../fonts/{filename}")
        except Exception as e:
            print(f"Failed to download {u}: {e}")
            
    with open(os.path.join(assets_dir, "css", css_filename), "w", encoding="utf-8") as f:
        f.write(css_content)

download_gfonts(gfonts_url, "fonts.css")

# Update links
files_to_update = [
    r"c:\xampp\htdocs\parking\index.php",
    r"c:\xampp\htdocs\parking\layouts\header.php",
    r"c:\xampp\htdocs\parking\layouts\dashboard_header.php",
    r"c:\xampp\htdocs\parking\extra\design\make this as base on the homepage.html",
    r"c:\xampp\htdocs\parking\extra\design\use this as base for the dashboards.html"
]

replacements = {
    '<script src="https://cdn.tailwindcss.com"></script>': '<script src="/parking/assets/js/tailwindcss.js"></script>',
    '<script src="https://unpkg.com/lucide@latest"></script>': '<script src="/parking/assets/js/lucide.js"></script>',
    '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">': '<link rel="stylesheet" href="/parking/assets/css/fontawesome.min.css">',
    '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">': '<link rel="stylesheet" href="/parking/assets/css/fontawesome.min.css">',
    '<link rel="preconnect" href="https://fonts.googleapis.com">\n': '',
    '<link rel="preconnect" href="https://fonts.googleapis.com">': '',
    '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>\n': '',
    '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>': '',
    '<link\n    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap"\n    rel="stylesheet">': '<link href="/parking/assets/css/fonts.css" rel="stylesheet">',
    '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">': '<link href="/parking/assets/css/fonts.css" rel="stylesheet">',
    "@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');": "@import url('/parking/assets/css/fonts.css');"
}

for fp in files_to_update:
    if os.path.exists(fp):
        with open(fp, "r", encoding="utf-8") as f:
            content = f.read()
        
        for old, new in replacements.items():
            content = content.replace(old, new)
            
        with open(fp, "w", encoding="utf-8") as f:
            f.write(content)
        print(f"Updated {fp}")

print("Done!")
