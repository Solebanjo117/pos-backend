import { Package, Tag } from 'lucide-react';
import { Card } from './ui/card';
import { Button } from './ui/button';
import { Badge } from './ui/badge';
import { Product } from '../types/catalog';

interface ProductCardProps {
  product: Product;
  viewMode: 'grid' | 'list';
  onSelect: () => void;
}

export function ProductCard({ product, viewMode, onSelect }: ProductCardProps) {
  const minPrice = Math.min(...product.brands.map(b => b.price));
  const availableBrands = product.brands.map(b => b.brand).join(', ');
  const hasStock = product.brands.some(b => b.stock > 0);

  if (viewMode === 'list') {
    return (
      <Card className="p-4 hover:shadow-md transition-shadow">
        <div className="flex gap-4">
          <img
            src={product.image}
            alt={product.name}
            className="w-32 h-32 object-cover rounded"
          />
          
          <div className="flex-1">
            <div className="flex items-start justify-between mb-2">
              <div>
                <h3 className="font-semibold">{product.name}</h3>
                <p className="text-sm text-gray-600">{product.model}</p>
                <div className="flex items-center gap-2 mt-1">
                  <Badge variant="outline" className="text-xs">
                    <Package className="w-3 h-3 mr-1" />
                    {product.code}
                  </Badge>
                  <Badge variant="secondary" className="text-xs">
                    {product.category}
                  </Badge>
                </div>
              </div>
              
              <div className="text-right">
                <p className="text-sm text-gray-600">Desde</p>
                <p className="text-xl font-semibold text-blue-600">
                  ${minPrice.toLocaleString()}
                </p>
              </div>
            </div>

            <div className="flex items-center justify-between mt-3">
              <div className="flex-1">
                <p className="text-xs text-gray-600 mb-1">Compatible con:</p>
                <p className="text-sm">
                  {product.compatibility.slice(0, 2).map(v => `${v.brand} ${v.model}`).join(', ')}
                  {product.compatibility.length > 2 && ` +${product.compatibility.length - 2} más`}
                </p>
                <p className="text-xs text-gray-600 mt-2">
                  <Tag className="w-3 h-3 inline mr-1" />
                  Marcas: {availableBrands}
                </p>
              </div>

              <div className="flex items-center gap-2 ml-4">
                {hasStock ? (
                  <Badge variant="default" className="bg-green-500">En Stock</Badge>
                ) : (
                  <Badge variant="secondary">Sin Stock</Badge>
                )}
                <Button onClick={onSelect}>
                  Ver Opciones
                </Button>
              </div>
            </div>
          </div>
        </div>
      </Card>
    );
  }

  return (
    <Card className="overflow-hidden hover:shadow-lg transition-shadow">
      <img
        src={product.image}
        alt={product.name}
        className="w-full h-48 object-cover"
      />
      
      <div className="p-4">
        <div className="mb-2">
          <h3 className="font-semibold line-clamp-1">{product.name}</h3>
          <p className="text-sm text-gray-600">{product.model}</p>
        </div>

        <div className="flex flex-wrap gap-1 mb-3">
          <Badge variant="outline" className="text-xs">
            <Package className="w-3 h-3 mr-1" />
            {product.code}
          </Badge>
          <Badge variant="secondary" className="text-xs">
            {product.category}
          </Badge>
        </div>

        <div className="mb-3">
          <p className="text-xs text-gray-600 mb-1">Compatible con:</p>
          <p className="text-sm line-clamp-2">
            {product.compatibility.slice(0, 2).map(v => `${v.brand} ${v.model}`).join(', ')}
            {product.compatibility.length > 2 && '...'}
          </p>
        </div>

        <div className="mb-3">
          <p className="text-xs text-gray-600">
            <Tag className="w-3 h-3 inline mr-1" />
            {availableBrands}
          </p>
        </div>

        <div className="flex items-center justify-between pt-3 border-t">
          <div>
            <p className="text-xs text-gray-600">Desde</p>
            <p className="text-lg font-semibold text-blue-600">
              ${minPrice.toLocaleString()}
            </p>
          </div>
          
          <Button onClick={onSelect} size="sm">
            Ver Opciones
          </Button>
        </div>
      </div>
    </Card>
  );
}
