/**
 * One home-page banner slide, as in the v2 design: a coloured panel with an
 * eyebrow, headline, body, a button and an optional promo code, with the
 * banner photo on the right when it has one.
 *
 * Shared by the storefront carousel and the admin banner editor's live
 * preview, so what staff see while editing is exactly what customers get.
 * Styled inline on purpose — it renders inside both the storefront and the
 * admin console, which have separate stylesheets.
 */
export default function BannerSlide({ banner, accent = '#ffd23f', onClick, compact = false }) {
  const bg = banner.bg_color || '#2a2829';
  const fg = banner.text_color || '#ffffff';
  const image = banner.image_url || null;

  return (
    <div
      role={onClick ? 'link' : undefined}
      tabIndex={onClick ? 0 : undefined}
      onClick={onClick}
      onKeyDown={onClick ? (e) => { if (e.key === 'Enter') onClick(); } : undefined}
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
      <div
        className={image ? 'sf-banner-art' : 'sf-banner-art sf-banner-art--empty'}
        style={{
          background: image ? `center / cover no-repeat url("${image}")` : 'rgba(255,255,255,.12)',
          minHeight: compact ? 120 : 200,
        }}
        role={image ? 'img' : undefined}
        aria-label={image ? banner.alt_text || banner.title : undefined}
      />
    </div>
  );
}
