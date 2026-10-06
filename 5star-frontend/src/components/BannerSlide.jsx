import { useEffect, useState } from 'react';

/**
 * One home-page banner slide, as in the v2 design.
 *
 * Two layouts (admin → Shopfront → Banners → Layout):
 *  - "split": a coloured panel with eyebrow, headline, body, button and promo
 *    code, and the banner photo beside it (below it on phones).
 *  - "image": the artwork alone, shown whole at its own shape — for designed
 *    posters that already carry their own headline. Never cropped.
 *
 * Phones get the banner's mobile artwork when one is uploaded. If an image
 * file can't be loaded (e.g. deleted from the server), the slide falls back
 * to the text panel instead of showing an empty box.
 *
 * Shared by the storefront carousel and the admin banner editor's live
 * preview, so what staff see while editing is exactly what customers get.
 * Styled inline on purpose — it renders inside both the storefront and the
 * admin console, which have separate stylesheets.
 */

const PHONE_QUERY = '(max-width: 640px)';

function Artwork({ image, mobileImage, alt, onFail, style, className }) {
  return (
    <picture className={className}>
      {mobileImage && mobileImage !== image && <source media={PHONE_QUERY} srcSet={mobileImage} />}
      <img src={image || mobileImage} alt={alt || ''} onError={onFail} style={style} />
    </picture>
  );
}

export default function BannerSlide({ banner, accent = '#ffd23f', onClick, compact = false }) {
  const bg = banner.bg_color || '#2a2829';
  const fg = banner.text_color || '#ffffff';
  const [failed, setFailed] = useState(false);
  const wide = banner.image_url || null;
  const phone = banner.mobile_image_url || null;

  useEffect(() => {
    setFailed(false);
  }, [wide, phone]);

  const image = failed ? null : (wide || phone);
  const linkProps = {
    role: onClick ? 'link' : undefined,
    tabIndex: onClick ? 0 : undefined,
    onClick,
    onKeyDown: onClick ? (e) => { if (e.key === 'Enter') onClick(); } : undefined,
  };

  // Picture-only banner: the artwork is the whole slide, never cropped.
  if (banner.layout === 'image' && image) {
    return (
      <div {...linkProps} className="sf-banner-poster" style={{ background: bg, cursor: onClick ? 'pointer' : 'default', lineHeight: 0 }}>
        <Artwork
          image={wide}
          mobileImage={phone}
          alt={banner.alt_text || banner.title}
          onFail={() => setFailed(true)}
          style={{ display: 'block', width: '100%', height: 'auto', maxHeight: compact ? 220 : 520, objectFit: 'contain', margin: '0 auto' }}
        />
      </div>
    );
  }

  return (
    <div
      {...linkProps}
      className="sf-banner-split"
      style={{
        display: 'grid',
        gridTemplateColumns: compact ? '1.3fr 1fr' : 'repeat(auto-fit, minmax(min(100%, 340px), 1fr))',
        minHeight: compact ? 170 : 260,
        background: bg,
        color: fg,
        cursor: onClick ? 'pointer' : 'default',
        transition: 'background .4s',
      }}
    >
      <div
        style={{
          padding: compact ? '20px 22px' : 'clamp(24px, 4vw, 44px) clamp(48px, 5vw, 64px)',
          display: 'flex',
          flexDirection: 'column',
          justifyContent: 'center',
          gap: compact ? 8 : 12,
        }}
      >
        {banner.eyebrow && (
          <span style={{ font: "800 12px/1 'Hanken Grotesk', system-ui, sans-serif", letterSpacing: '.14em', textTransform: 'uppercase', opacity: 0.8 }}>
            {banner.eyebrow}
          </span>
        )}
        <span
          style={{
            font: `800 ${compact ? '24px' : 'clamp(28px, 4.4vw, 48px)'}/1.02 'Bricolage Grotesque', system-ui, sans-serif`,
            letterSpacing: '-0.03em',
            textWrap: 'balance',
          }}
        >
          {banner.title || 'Headline'}
        </span>
        {banner.subtitle && (
          <span style={{ font: `500 ${compact ? 13 : 16}px/1.45 'Hanken Grotesk', system-ui, sans-serif`, opacity: 0.9, maxWidth: '42ch' }}>
            {banner.subtitle}
          </span>
        )}
        {(banner.cta_label || banner.promo_code) && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap', paddingTop: 6 }}>
            {banner.cta_label && (
              <span
                style={{
                  height: compact ? 36 : 44,
                  padding: '0 22px',
                  borderRadius: 6,
                  background: accent,
                  color: '#2a2829',
                  font: `800 ${compact ? 13 : 15}px/${compact ? 36 : 44}px 'Hanken Grotesk', system-ui, sans-serif`,
                  whiteSpace: 'nowrap',
                }}
              >
                {banner.cta_label}
              </span>
            )}
            {banner.promo_code && (
              <span
                style={{
                  font: "800 13px/1 'Hanken Grotesk', system-ui, sans-serif",
                  letterSpacing: '.08em',
                  padding: '10px 12px',
                  borderRadius: 6,
                  whiteSpace: 'nowrap',
                  border: `1.5px dashed ${fg}`,
                }}
              >
                {banner.promo_code}
              </span>
            )}
          </div>
        )}
      </div>
      {image ? (
        <Artwork
          className="sf-banner-art"
          image={wide}
          mobileImage={phone}
          alt={banner.alt_text || banner.title}
          onFail={() => setFailed(true)}
          style={{ display: 'block', width: '100%', height: '100%', minHeight: compact ? 120 : 200, objectFit: 'cover' }}
        />
      ) : (
        <div className="sf-banner-art sf-banner-art--empty" style={{ background: 'rgba(255,255,255,.12)', minHeight: compact ? 120 : 200 }} />
      )}
    </div>
  );
}
