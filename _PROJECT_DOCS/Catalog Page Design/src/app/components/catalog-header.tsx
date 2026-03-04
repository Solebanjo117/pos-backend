import { Search, Car, Grid3x3 } from 'lucide-react';
import { Input } from './ui/input';
import { Button } from './ui/button';
import { Badge } from './ui/badge';
import { Vehicle } from '../types/catalog';
import { categories } from '../data/mock-products';

interface CatalogHeaderProps {
  searchQuery: string;
  onSearch: (query: string) => void;
  onOpenVehicleSelector: () => void;
  selectedVehicle: Vehicle | null;
  onCategorySelect: (category: string) => void;
  selectedCategory: string | null;
}

export function CatalogHeader({
  searchQuery,
  onSearch,
  onOpenVehicleSelector,
  selectedVehicle,
  onCategorySelect,
  selectedCategory
}: CatalogHeaderProps) {
  return (
    <header className="bg-white shadow-sm border-b">
      <div className="container mx-auto px-4 py-4">
        {/* Logo and Search */}
        <div className="flex items-center gap-4 mb-4">
          <div className="flex items-center gap-2">
            <Grid3x3 className="w-8 h-8 text-blue-600" />
            <h1 className="text-xl font-semibold">Catálogo de Repuestos</h1>
          </div>
          
          <div className="flex-1 max-w-2xl">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
              <Input
                type="text"
                placeholder="Buscar por código / nombre / modelo"
                value={searchQuery}
                onChange={(e) => onSearch(e.target.value)}
                className="pl-10"
              />
            </div>
          </div>

          <Button
            onClick={onOpenVehicleSelector}
            variant="outline"
            className="gap-2"
          >
            <Car className="w-4 h-4" />
            Seleccionar Vehículo
          </Button>
        </div>

        {/* Selected Vehicle Display */}
        {selectedVehicle && (
          <div className="mb-3">
            <Badge variant="secondary" className="text-sm py-1 px-3">
              <Car className="w-3 h-3 mr-1 inline" />
              {selectedVehicle.brand} {selectedVehicle.model} - {selectedVehicle.series} ({selectedVehicle.year})
            </Badge>
          </div>
        )}

        {/* Quick Category Buttons */}
        <div className="flex flex-wrap gap-2">
          <Button
            size="sm"
            variant={selectedCategory === null ? 'default' : 'outline'}
            onClick={() => onCategorySelect(null as any)}
          >
            Todas
          </Button>
          {categories.map((category) => (
            <Button
              key={category}
              size="sm"
              variant={selectedCategory === category ? 'default' : 'outline'}
              onClick={() => onCategorySelect(category)}
            >
              {category}
            </Button>
          ))}
        </div>
      </div>
    </header>
  );
}
