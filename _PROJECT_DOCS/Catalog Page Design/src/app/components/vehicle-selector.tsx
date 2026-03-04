import { useState } from 'react';
import { X, Car } from 'lucide-react';
import { Button } from './ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import { Vehicle } from '../types/catalog';
import { vehicleBrands } from '../data/mock-products';

interface VehicleSelectorProps {
  onSelect: (vehicle: Vehicle) => void;
  onClose: () => void;
}

// Mock data para modelos y series
const mockModels: Record<string, string[]> = {
  'Toyota': ['Corolla', 'Yaris', 'RAV4', 'Hilux', 'Camry'],
  'Honda': ['Civic', 'Fit', 'CR-V', 'Accord', 'HR-V'],
  'Nissan': ['Sentra', 'Versa', 'March', 'X-Trail', 'Kicks'],
  'Ford': ['Focus', 'Fiesta', 'Ranger', 'EcoSport', 'Mustang'],
  'Chevrolet': ['Cruze', 'Spark', 'Trax', 'Equinox', 'Silverado'],
  'Mazda': ['3', 'CX-5', 'CX-30', '6', 'CX-9'],
  'Volkswagen': ['Golf', 'Jetta', 'Polo', 'Tiguan', 'Passat'],
  'Hyundai': ['Elantra', 'Accent', 'Tucson', 'Santa Fe', 'Kona'],
  'Kia': ['Forte', 'Cerato', 'Sportage', 'Rio', 'Seltos'],
  'BMW': ['320i', '318i', 'X3', 'X5', '530i'],
  'Mercedes-Benz': ['C200', 'E200', 'GLC', 'A200', 'CLA'],
  'Audi': ['A3', 'A4', 'Q5', 'Q3', 'A6'],
  'Subaru': ['XV', 'Impreza', 'Forester', 'Outback', 'WRX'],
};

const mockSeries: Record<string, string[]> = {
  'Corolla': ['E210', 'E170', 'E160'],
  'Yaris': ['XP210', 'XP150', 'XP130'],
  'Civic': ['FC', 'FK', 'FD'],
  'Focus': ['Mk4', 'Mk3', 'Mk2'],
  'Golf': ['Mk8', 'Mk7', 'Mk6'],
  'default': ['Serie A', 'Serie B', 'Serie C'],
};

export function VehicleSelector({ onSelect, onClose }: VehicleSelectorProps) {
  const [selectedBrand, setSelectedBrand] = useState('');
  const [selectedModel, setSelectedModel] = useState('');
  const [selectedSeries, setSelectedSeries] = useState('');
  const [selectedYear, setSelectedYear] = useState('');

  const currentYear = 2026;
  const years = Array.from({ length: 15 }, (_, i) => currentYear - i);

  const handleSubmit = () => {
    if (selectedBrand && selectedModel && selectedSeries && selectedYear) {
      onSelect({
        brand: selectedBrand,
        model: selectedModel,
        series: selectedSeries,
        year: parseInt(selectedYear)
      });
    }
  };

  const availableModels = selectedBrand ? mockModels[selectedBrand] || [] : [];
  const availableSeries = selectedModel ? (mockSeries[selectedModel] || mockSeries.default) : [];

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
        {/* Header */}
        <div className="flex items-center justify-between p-4 border-b">
          <div className="flex items-center gap-2">
            <Car className="w-5 h-5 text-blue-600" />
            <h2 className="text-lg font-semibold">Selector de Vehículo</h2>
          </div>
          <Button variant="ghost" size="sm" onClick={onClose}>
            <X className="w-4 h-4" />
          </Button>
        </div>

        {/* Content */}
        <div className="p-6 space-y-4">
          {/* Marca */}
          <div className="space-y-2">
            <label className="text-sm font-medium">Marca</label>
            <Select value={selectedBrand} onValueChange={setSelectedBrand}>
              <SelectTrigger>
                <SelectValue placeholder="Seleccionar marca" />
              </SelectTrigger>
              <SelectContent>
                {vehicleBrands.map((brand) => (
                  <SelectItem key={brand} value={brand}>
                    {brand}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Modelo */}
          <div className="space-y-2">
            <label className="text-sm font-medium">Modelo</label>
            <Select 
              value={selectedModel} 
              onValueChange={setSelectedModel}
              disabled={!selectedBrand}
            >
              <SelectTrigger>
                <SelectValue placeholder="Seleccionar modelo" />
              </SelectTrigger>
              <SelectContent>
                {availableModels.map((model) => (
                  <SelectItem key={model} value={model}>
                    {model}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Serie */}
          <div className="space-y-2">
            <label className="text-sm font-medium">Serie / Generación</label>
            <Select 
              value={selectedSeries} 
              onValueChange={setSelectedSeries}
              disabled={!selectedModel}
            >
              <SelectTrigger>
                <SelectValue placeholder="Seleccionar serie" />
              </SelectTrigger>
              <SelectContent>
                {availableSeries.map((series) => (
                  <SelectItem key={series} value={series}>
                    {series}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Año */}
          <div className="space-y-2">
            <label className="text-sm font-medium">Año</label>
            <Select 
              value={selectedYear} 
              onValueChange={setSelectedYear}
              disabled={!selectedSeries}
            >
              <SelectTrigger>
                <SelectValue placeholder="Seleccionar año" />
              </SelectTrigger>
              <SelectContent>
                {years.map((year) => (
                  <SelectItem key={year} value={year.toString()}>
                    {year}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>

        {/* Footer */}
        <div className="flex gap-2 p-4 border-t bg-gray-50">
          <Button variant="outline" onClick={onClose} className="flex-1">
            Cancelar
          </Button>
          <Button 
            onClick={handleSubmit} 
            className="flex-1"
            disabled={!selectedBrand || !selectedModel || !selectedSeries || !selectedYear}
          >
            Ver Repuestos
          </Button>
        </div>
      </div>
    </div>
  );
}
