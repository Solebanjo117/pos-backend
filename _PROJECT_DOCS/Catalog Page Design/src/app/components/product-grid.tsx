import { LayoutGrid, List } from 'lucide-react';
import { Button } from './ui/button';
import { Product } from '../types/catalog';
import { ProductCard } from './product-card';

interface ProductGridProps {
  products: Product[];
  viewMode: 'grid' | 'list';
  onViewModeChange: (mode: 'grid' | 'list') => void;
  onProductSelect: (product: Product) => void;
}

export function ProductGrid({ 
  products, 
  viewMode, 
  onViewModeChange, 
  onProductSelect 
}: ProductGridProps) {
  return (
    <div>
      {/* Header con toggle de vista */}
      <div className="flex items-center justify-between mb-4">
        <p className="text-sm text-gray-600">
          {products.length} producto{products.length !== 1 ? 's' : ''} encontrado{products.length !== 1 ? 's' : ''}
        </p>
        
        <div className="flex gap-1">
          <Button
            variant={viewMode === 'grid' ? 'default' : 'outline'}
            size="sm"
            onClick={() => onViewModeChange('grid')}
          >
            <LayoutGrid className="w-4 h-4" />
          </Button>
          <Button
            variant={viewMode === 'list' ? 'default' : 'outline'}
            size="sm"
            onClick={() => onViewModeChange('list')}
          >
            <List className="w-4 h-4" />
          </Button>
        </div>
      </div>

      {/* Grid o Lista de productos */}
      {products.length === 0 ? (
        <div className="text-center py-12 text-gray-500">
          <p>No se encontraron productos con los filtros seleccionados</p>
        </div>
      ) : (
        <div className={
          viewMode === 'grid' 
            ? 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4'
            : 'flex flex-col gap-4'
        }>
          {products.map((product) => (
            <ProductCard
              key={product.id}
              product={product}
              viewMode={viewMode}
              onSelect={() => onProductSelect(product)}
            />
          ))}
        </div>
      )}
    </div>
  );
}
