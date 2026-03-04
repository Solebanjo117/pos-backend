import { Link } from 'react-router';

export function NotFound() {
  return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="text-center">
        <h1 className="text-4xl font-bold mb-4">404</h1>
        <p className="text-gray-600 mb-4">Página no encontrada</p>
        <Link to="/" className="text-blue-600 hover:underline">
          Volver al catálogo
        </Link>
      </div>
    </div>
  );
}
