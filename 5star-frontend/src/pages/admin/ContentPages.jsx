/**
 * Pages tab. Ported from page-content.js's renderPages() — a read-only list
 * of the static pages (shipping, returns, privacy, terms). Note the source
 * calls `/content/pages`, not an `/admin/...` path.
 */
import { useEffect, useState } from 'react';
import { api } from '../../lib/api';
import { LoadingState, ErrorState } from '../../components/admin/shared.jsx';
import './Content.css';

export default function ContentPages() {
  const [pages, setPages] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const response = await api.get('/content/pages');
        if (!cancelled) setPages(response.data.pages || []);
      } catch (err) {
        if (!cancelled) setError(err);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
  }, []);

  return (
    <div>
      <div className="content-card">
        <div className="content-card__header">Pages</div>
        {loading ? (
          <LoadingState />
        ) : error ? (
          <div className="content-card__body"><ErrorState error={error} /></div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="admin-table">
              <thead>
                <tr><th>Title</th><th>Address</th><th>Updated</th></tr>
              </thead>
              <tbody>
                {pages.map((page) => (
                  <tr key={page.slug}>
                    <td style={{ fontWeight: 600 }}>{page.title}</td>
                    <td className="content-subtext">{page.slug}</td>
                    <td>{String(page.published_date || '').slice(0, 10)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
      <p className="content-note">
        Shipping, returns, privacy and terms ship with placeholder wording. They are a contract
        with your customer — have them reviewed before you go live.
      </p>
    </div>
  );
}
