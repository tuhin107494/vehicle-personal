import { api } from "@/lib/api";

/** What the user did to a photo in a form: nothing, chose a new file, or removed it. */
export type PhotoChange = File | "remove" | null;

/** Applies a photo change to a saved record, e.g. basePath "/customers/5". Throws like `api` does. */
export async function savePhoto(basePath: string, change: PhotoChange): Promise<string | null | undefined> {
  if (change === null) return undefined;
  if (change === "remove") {
    await api(`${basePath}/photo`, { method: "DELETE" });
    return null;
  }
  const form = new FormData();
  form.append("photo", change);
  return (await api<{ photo_url: string }>(`${basePath}/photo`, { method: "POST", body: form })).data.photo_url;
}
