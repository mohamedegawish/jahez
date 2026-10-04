/** Red outline for an input whose field has a server (422) error. */
export const fieldErrorClass = (hasError: boolean): string =>
  hasError ? 'border-[#E45B6A] focus:border-[#E45B6A]' : '';

/** Append a text value to a multipart form, skipping empty optional values. */
export function appendText(form: FormData, key: string, value: string): void {
  const trimmed = value.trim();
  if (trimmed !== '') form.append(key, trimmed);
}

/** Append a list the way Laravel reads it from multipart data: `key[]`. */
export function appendList(form: FormData, key: string, values: string[]): void {
  values.forEach((value) => form.append(`${key}[]`, value));
}

export function appendFile(form: FormData, key: string, file: File | null): void {
  if (file) form.append(key, file);
}
