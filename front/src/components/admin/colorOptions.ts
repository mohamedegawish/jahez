import { AdColor } from '../../types';

export interface AdPalette {
  key: AdColor;
  label: string;
  /** the brand color of the palette (badge/arrow accent) */
  hex: string;
  /** very light tint used behind covers / soft surfaces */
  soft: string;
  /** readable dark tone of the same hue (text/icons on light bg) */
  ink: string;
  /** extremely light focus tint drawn on the card border/ring */
  focus: string;
}

export const colorOptions: AdPalette[] = [
  { key: 'green', label: 'أخضر', hex: '#35B779', soft: '#E7F8EE', ink: '#1D7E4C', focus: '#D7F2E5' },
  { key: 'blue', label: 'أزرق', hex: '#6EC8FF', soft: '#DFF3FF', ink: '#0A6EB0', focus: '#D9F0FF' },
  { key: 'orange', label: 'برتقالي', hex: '#F2B84B', soft: '#FFF3E2', ink: '#B26A09', focus: '#FDF0D6' },
  { key: 'red', label: 'أحمر', hex: '#E45B6A', soft: '#FDECEE', ink: '#C0394B', focus: '#FADDE1' },
  { key: 'beige', label: 'بيج', hex: '#C9A66B', soft: '#F8F2E6', ink: '#7E6032', focus: '#F1E9D9' },
  { key: 'purple', label: 'بنفسجي', hex: '#9B8AFB', soft: '#EEEAFE', ink: '#5146A5', focus: '#E6E2FE' }
];

/** Resolve the full palette for an ad color key (falls back to purple). */
export function getAdPalette(color: AdColor | string): AdPalette {
  return colorOptions.find((c) => c.key === color) ?? colorOptions[colorOptions.length - 1];
}
