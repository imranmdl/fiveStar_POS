/** Placeholder for a page not yet ported to React. Swap the route's element for the real component as each page lands. */
export default function ComingSoon({ title, note }) {
  return (
    <div style={{ padding: '64px 16px', textAlign: 'center' }}>
      <h1 style={{ marginBottom: 8, fontSize: 22 }}>{title}</h1>
      <p style={{ color: '#888', fontSize: 14 }}>{note || 'This page is being migrated to React.'}</p>
    </div>
  );
}
