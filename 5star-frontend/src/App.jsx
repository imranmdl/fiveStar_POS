import { Route, Routes } from 'react-router-dom';
import CustomerLayout from './components/customer/CustomerLayout';
import AdminLayout from './components/admin/AdminLayout';
import ComingSoon from './components/ComingSoon';
import { customerRoutes } from './routes/customerRoutes';
import { adminRoutes } from './routes/adminRoutes';
import Till from './pages/till/Till';

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<CustomerLayout />}>
        {customerRoutes.map((route) => (
          <Route key={route.path || 'index'} index={route.index} path={route.path} element={route.element} />
        ))}
      </Route>

      <Route path="/admin" element={<AdminLayout />}>
        {adminRoutes.map((route) => (
          <Route key={route.path || 'index'} index={route.index} path={route.path} element={route.element} />
        ))}
      </Route>

      {/*
        The Till (POS checkout) is deliberately NOT nested under AdminLayout.
        The live admin/till.html has its own separate cashier sign-in, on
        purpose, so a console session left open on someone else's screen
        can't be used to ring up sales — see admin/assets/page-till.js's own
        top comment (requireTillSignIn). lib/api.js's IS_TILL check matches
        on a path starting with "/till", which is why this route lives here
        at the top level instead of under /admin.
      */}
      <Route path="/till" element={<Till />} />
    </Routes>
  );
}
