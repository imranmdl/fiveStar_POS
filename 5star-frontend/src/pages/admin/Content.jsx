/**
 * Shopfront: categories, campaign pages, adverts and the static pages —
 * the merchandising a shop owner changes weekly. Ported from the live
 * admin/assets/page-content.js. Four tabs because they are one job:
 * deciding what the shop puts in front of people.
 */
import { useSearchParams } from 'react-router-dom';
import ContentCategories from './ContentCategories';
import ContentCollections from './ContentCollections';
import ContentBanners from './ContentBanners';
import ContentPages from './ContentPages';
import ContentAppearance from './ContentAppearance';
import './Content.css';

const TABS = [
  ['appearance', 'Appearance'],
  ['banners', 'Banners'],
  ['categories', 'Categories'],
  ['collections', 'Campaign pages'],
  ['pages', 'Pages'],
];

export default function Content() {
  const [searchParams, setSearchParams] = useSearchParams();
  const tab = searchParams.get('tab') || 'appearance';

  // A festival on the Festival Calendar screen can open straight into its
  // own campaign page here (?tab=collections&open=<slug>), instead of
  // making staff find it again in the list.
  const openSlug = tab === 'collections' ? searchParams.get('open') : null;

  function switchTab(next) {
    const params = new URLSearchParams(searchParams);
    params.set('tab', next);
    params.delete('open');
    setSearchParams(params);
  }

  return (
    <div>
      <div className="admin-toolbar" style={{ justifyContent: 'space-between' }}>
        <h1 className="admin-page-title" style={{ margin: 0 }}>Shopfront</h1>

        <div className="content-tabs">
          {TABS.map(([key, label]) => (
            <button
              key={key}
              type="button"
              className={`admin-btn ${tab === key ? 'admin-btn--primary' : ''}`}
              onClick={() => switchTab(key)}
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {tab === 'appearance' && <ContentAppearance />}
      {tab === 'categories' && <ContentCategories />}
      {tab === 'collections' && <ContentCollections initialOpenSlug={openSlug} />}
      {tab === 'banners' && <ContentBanners />}
      {tab === 'pages' && <ContentPages />}
    </div>
  );
}
