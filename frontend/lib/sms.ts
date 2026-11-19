import type { SmsStage } from "@/lib/types";

/** The four due-alert stages, in the order they go out. */
export const STAGES: { stage: SmsStage; label: string }[] = [
  { stage: "upcoming", label: "Before the due date" },
  { stage: "today", label: "On the due date" },
  { stage: "overdue", label: "Overdue" },
  { stage: "final", label: "Final reminder" },
];

export const stageLabel = (stage: SmsStage | null) => STAGES.find((s) => s.stage === stage)?.label ?? "Test SMS";

/** Characters and SMS parts, as the server counts them: 160/153 in plain text, 70/67 once there is Bangla or other Unicode. */
export function smsParts(text: string): { length: number; parts: number; unicode: boolean } {
  const length = [...text].length;
  const unicode = /[^\n\r\x20-\x7E]/.test(text);
  const [single, multi] = unicode ? [70, 67] : [160, 153];
  return { length, parts: length <= single ? 1 : Math.ceil(length / multi), unicode };
}
