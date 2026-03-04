import { FilterState } from '../types/catalog';
import { categories, brands } from '../data/mock-products';
import { Checkbox } from './ui/checkbox';
import { Label } from './ui/label';
import { Slider } from './ui/slider';
import { RadioGroup, RadioGroupItem } from './ui/radio-group';
import { Card } from './ui/card';
import { Filter } from 'lucide-react';

interface ProductFiltersProps {
  filters: FilterState;
  onFiltersChange: (filters: FilterState) => void;
}

export function ProductFilters({ filters, onFiltersChange }: ProductFiltersProps) {
  const handleCategoryToggle = (category: string) => {
    const newCategories = filters.categories.includes(category)
      ? filters.categories.filter(c => c !== category)
      : [...filters.categories, category];
    onFiltersChange({ ...filters, categories: newCategories });
  };

  const handleBrandToggle = (brand: string) => {
    const newBrands = filters.brands.includes(brand)
      ? filters.brands.filter(b => b !== brand)
      : [...filters.brands, brand];
    onFiltersChange({ ...filters, brands: newBrands });
  };

  const handlePriceChange = (value: number[]) => {
    onFiltersChange({ ...filters, priceRange: [value[0], value[1]] });
  };

  const handleAvailabilityChange = (value: string) => {
    onFiltersChange({ ...filters, availability: value as FilterState['availability'] });
  };

  return (
    <Card className="p-4 sticky top-4">
      <div className="flex items-center gap-2 mb-4">
        <Filter className="w-4 h-4" />
        <h2 className="font-semibold">Filtros</h2>
      </div>

      <div className="space-y-6">
        {/* Categorías */}
        <div>
          <h3 className="font-medium mb-3">Categoría</h3>
          <div className="space-y-2">
            {categories.map((category) => (
              <div key={category} className="flex items-center space-x-2">
                <Checkbox
                  id={`cat-${category}`}
                  checked={filters.categories.includes(category)}
                  onCheckedChange={() => handleCategoryToggle(category)}
                />
                <Label htmlFor={`cat-${category}`} className="text-sm cursor-pointer">
                  {category}
                </Label>
              </div>
            ))}
          </div>
        </div>

        {/* Rango de Precio */}
        <div>
          <h3 className="font-medium mb-3">Rango de Precio</h3>
          <div className="space-y-3">
            <Slider
              min={0}
              max={30000}
              step={500}
              value={filters.priceRange}
              onValueChange={handlePriceChange}
            />
            <div className="flex justify-between text-sm text-gray-600">
              <span>${filters.priceRange[0].toLocaleString()}</span>
              <span>${filters.priceRange[1].toLocaleString()}</span>
            </div>
          </div>
        </div>

        {/* Disponibilidad */}
        <div>
          <h3 className="font-medium mb-3">Disponibilidad</h3>
          <RadioGroup value={filters.availability} onValueChange={handleAvailabilityChange}>
            <div className="flex items-center space-x-2">
              <RadioGroupItem value="all" id="avail-all" />
              <Label htmlFor="avail-all" className="text-sm cursor-pointer">
                Todos
              </Label>
            </div>
            <div className="flex items-center space-x-2">
              <RadioGroupItem value="in-stock" id="avail-in" />
              <Label htmlFor="avail-in" className="text-sm cursor-pointer">
                En Stock
              </Label>
            </div>
            <div className="flex items-center space-x-2">
              <RadioGroupItem value="out-of-stock" id="avail-out" />
              <Label htmlFor="avail-out" className="text-sm cursor-pointer">
                Sin Stock
              </Label>
            </div>
          </RadioGroup>
        </div>

        {/* Marcas */}
        <div>
          <h3 className="font-medium mb-3">Marca</h3>
          <div className="space-y-2 max-h-48 overflow-y-auto">
            {brands.map((brand) => (
              <div key={brand} className="flex items-center space-x-2">
                <Checkbox
                  id={`brand-${brand}`}
                  checked={filters.brands.includes(brand)}
                  onCheckedChange={() => handleBrandToggle(brand)}
                />
                <Label htmlFor={`brand-${brand}`} className="text-sm cursor-pointer">
                  {brand}
                </Label>
              </div>
            ))}
          </div>
        </div>
      </div>
    </Card>
  );
}
