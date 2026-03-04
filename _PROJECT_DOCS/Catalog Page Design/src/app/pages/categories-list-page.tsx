import { useState } from 'react';
import { Link } from 'react-router';
import { Settings, Plus, FolderTree } from 'lucide-react';
import { Card } from '../components/ui/card';
import { Button } from '../components/ui/button';
import { Badge } from '../components/ui/badge';
import { mockCategories } from '../data/mock-categories';

export function CategoriesListPage() {
  const [categories] = useState(mockCategories);

  return (
    <div className="container mx-auto px-4 py-6">
      {/* Header */}
      <div className="mb-6">
        <div className="flex items-center justify-between mb-2">
          <div className="flex items-center gap-3">
            <FolderTree className="w-8 h-8 text-blue-600" />
            <div>
              <h1 className="text-3xl font-bold">Gestión de Categorías</h1>
              <p className="text-gray-600">Administra las categorías y sus atributos personalizados</p>
            </div>
          </div>
          <Button className="gap-2">
            <Plus className="w-4 h-4" />
            Nueva Categoría
          </Button>
        </div>
      </div>

      {/* Categories Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        {categories.map((category) => (
          <Card key={category.id} className="p-5 hover:shadow-lg transition-shadow">
            <div className="flex items-start justify-between mb-3">
              <div className="flex-1">
                <h3 className="text-lg font-semibold mb-1">{category.name}</h3>
                <Badge variant="secondary" className="text-xs">
                  {category.attributes.length} atributo{category.attributes.length !== 1 ? 's' : ''}
                </Badge>
              </div>
              <Settings className="w-5 h-5 text-gray-400" />
            </div>

            {/* Preview de atributos */}
            <div className="mb-4 space-y-1">
              {category.attributes.slice(0, 3).map((attr) => (
                <div key={attr.id} className="text-sm text-gray-600 flex items-center gap-2">
                  <span className="w-2 h-2 bg-blue-500 rounded-full"></span>
                  {attr.name}
                </div>
              ))}
              {category.attributes.length > 3 && (
                <div className="text-xs text-gray-500 ml-4">
                  +{category.attributes.length - 3} más
                </div>
              )}
            </div>

            {/* Actions */}
            <Link to={`/admin/categories/${category.id}/attributes`}>
              <Button variant="outline" className="w-full gap-2">
                <Settings className="w-4 h-4" />
                Editar Atributos
              </Button>
            </Link>
          </Card>
        ))}
      </div>
    </div>
  );
}
