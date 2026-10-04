import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, ApiError } from '../../lib/api';
import './Page.css';

/** Splits on blank lines into paragraphs, preserving single line breaks as <br>. */
function Paragraphs({ body }) {
  const blocks = String(body || '').split(/\n{2,}/);
  return (
    <>
      {blocks.map((block, index) => (
        <p key={index}>
          {block.split('\n').map((line, i) => (
            <span key={i}>
              {line}
              {i < block.split('\n').length - 1 && <br />}
            </span>
          ))}
        </p>
      ))}
    </>
  );
}

export default function Page() {
  const { slug } = useParams();
  const [status, setStatus] = useState('loading');
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState(null);
  const [page, setPage] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setStatus('loading');
    setNotFound(false);

    api
      .get(`/content/pages/${encodeURIComponent(slug)}`)
      .then((response) => {
        if (cancelled) return;
        setPage(response.data.page);
        document.title = `${response.data.page.title} · 5Star Spices`;
        setStatus('ready');
      })
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 404) {
          setNotFound(true);
          setStatus('ready');
          return;
        }
        setError(err.message);
        setStatus('error');
      });

    return () => {
      cancelled = true;
    };
  }, [slug]);

  if (status === 'loading') {
    return <div className="page"><p className="state-message">Loading…</p></div>;
  }

  if (notFound) {
    return (
      <div className="page">
        <p className="state-message">
          That page does not exist. <Link to="/">Back to the shop</Link>.
        </p>
      </div>
    );
  }

  if (status === 'error') {
    return <div className="page"><p className="state-message state-message--error">Couldn't load this page: {error}</p></div>;
  }

  return (
    <div className="page cms-page">
      <h1>{page.title}</h1>
      {page.updated_date && (
        <p className="cms-page__updated">Last updated {String(page.updated_date).slice(0, 10)}</p>
      )}
      <div className="cms-page__body">
        <Paragraphs body={page.body} />
      </div>
    </div>
  );
}
