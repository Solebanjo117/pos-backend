import { createBrowserRouter } from 'react-router';
import { RootLayout } from './layouts/root-layout';
import { CatalogPage } from './pages/catalog-page';
import { CategoriesListPage } from './pages/categories-list-page';
import { CategoryAttributesPage } from './pages/category-attributes-page';
import { NotFound } from './pages/not-found';

export const router = createBrowserRouter([
  {
    path: '/',
    Component: RootLayout,
    children: [
      {
        index: true,
        Component: CatalogPage,
      },
      {
        path: 'admin/categories',
        Component: CategoriesListPage,
      },
      {
        path: 'admin/categories/:categoryId/attributes',
        Component: CategoryAttributesPage,
      },
      {
        path: '*',
        Component: NotFound,
      },
    ],
  },
]);
