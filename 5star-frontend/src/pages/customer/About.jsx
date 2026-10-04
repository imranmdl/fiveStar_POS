import { useEffect } from 'react';
import { Link } from 'react-router-dom';

/*
 * Copy is from the Claude Design file, where it is marked as a draft for the
 * owner to replace with the real story — edit the text here.
 */
const SOURCING = [
  { title: 'Turmeric from Erode', place: 'Erode', tint: '#f3e2b3', body: 'Sun-dried fingers, stone-ground in small batches to keep the colour and aroma.' },
  { title: 'Cardamom from Idukki', place: 'Idukki', tint: '#dfe8cf', body: 'Bold 8mm pods, bought each season from the same hills.' },
  { title: 'Nuts sorted by hand', place: 'Packing floor', tint: '#efdfca', body: 'Every lot is graded for size and screened for shell before it is packed.' },
];

export default function About() {
  useEffect(() => {
    document.title = 'Our story · 5 Star';
  }, []);

  return (
    <div className="sf-stack-72">
      <section className="sf-wrap sf-about-hero">
        <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
          <span className="sf-eyebrow">Our story</span>
          <h1 className="sf-hero__title" style={{ fontSize: 'clamp(36px, 6vw, 64px)' }}>Selling spices and dry fruits since 1984</h1>
          <p className="sf-lead" style={{ fontSize: 17, lineHeight: 1.6, color: 'var(--sf-ink-2)', maxWidth: '50ch' }}>
            We started as a neighbourhood counter and still buy the way we did then: directly from growers and trusted
            traders, in quantities small enough to keep everything fresh.
          </p>
          <div><Link to="/shop" className="sf-btn sf-btn--red sf-btn--xl">Shop now</Link></div>
        </div>
        <div style={{ display: 'flex', justifyContent: 'center', padding: 24 }}>
          <img src="/brand/logo-512.png" alt="5 Star, since 1984" width="340" height="340" />
        </div>
      </section>

      <section className="sf-wrap sf-sourcing">
        {SOURCING.map((item) => (
          <div key={item.title}>
            <div className="sf-media" style={{ background: item.tint }}>
              <span className="sf-media__label">Photo · {item.place}</span>
            </div>
            <b>{item.title}</b>
            <span>{item.body}</span>
          </div>
        ))}
      </section>
    </div>
  );
}
