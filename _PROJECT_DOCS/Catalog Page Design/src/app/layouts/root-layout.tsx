import { Outlet, Link, useLocation } from 'react-router';
import { Home, FolderTree } from 'lucide-react';
import { Button } from '../components/ui/button';

export function RootLayout() {
  const location = useLocation();
  const isAdminPage = location.pathname.startsWith('/admin');

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Navigation Bar */}
      <nav className="bg-white border-b shadow-sm">
        <div className="container mx-auto px-4 py-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-4">
              <h1 className="text-xl font-bold text-blue-600">Sistema de Repuestos</h1>
              <div className="flex gap-2">
                <Link to="/">
                  <Button 
                    variant={!isAdminPage ? 'default' : 'ghost'}
                    size="sm"
                    className="gap-2"
                  >
                    <Home className="w-4 h-4" />
                    Catálogo
                  </Button>
                </Link>
                <Link to="/admin/categories">
                  <Button 
                    variant={isAdminPage ? 'default' : 'ghost'}
                    size="sm"
                    className="gap-2"
                  >
                    <FolderTree className="w-4 h-4" />
                    Administrar Categorías
                  </Button>
                </Link>
              </div>
            </div>
          </div>
        </div>
      </nav>

      {/* Main Content */}
      <Outlet />
    </div>
  );
}
