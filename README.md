# JudesCBO
## Fonts and icons

Fonts (Montserrat, Raleway) and Font Awesome icons are served from this site (`fonts/`, `css/fonts.css`), trimmed to the icons the site uses. After using a new `fa-…` icon anywhere, rebuild them:

    pip install fonttools brotli
    python tools/build-fonts.py
