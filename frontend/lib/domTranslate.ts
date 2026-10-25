import { BN } from "@/lib/bn";

const ATTRS = ["placeholder", "title", "aria-label"];
const originals = new Map<Node, string>();
const attrOriginals = new Map<Element, Record<string, string>>();

const BN_DIGITS = "০১২৩৪৫৬৭৮৯";
const bnNum = (s: string) => s.replace(/\d/g, (d) => BN_DIGITS[Number(d)]);

/** Phrases that carry a number or a name: matched by pattern, not by exact text. */
const MONTHS: Record<string, string> = { Jan: "জানু", Feb: "ফেব", Mar: "মার্চ", Apr: "এপ্রিল", May: "মে", Jun: "জুন", Jul: "জুলাই", Aug: "আগস্ট", Sep: "সেপ্টেম্বর", Sept: "সেপ্টেম্বর", Oct: "অক্টোবর", Nov: "নভেম্বর", Dec: "ডিসেম্বর" };

const RULES: [RegExp, (m: RegExpMatchArray) => string][] = [
  [/^(\d{1,2}) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sept?|Oct|Nov|Dec) (\d{4})$/, (m) => `${bnNum(m[1])} ${MONTHS[m[2]]} ${bnNum(m[3])}`],
  [/^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sept?|Oct|Nov|Dec)$/, (m) => MONTHS[m[1]]],
  [/^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sept?|Oct|Nov|Dec) (Sales|Purchases|Collected) ([\d,.]+)$/, (m) => `${MONTHS[m[1]]} · ${BN[m[2]] ?? m[2]} ${m[3]}`],
  [/^([\d.]+)(Cr|L|k)$/, (m) => `${bnNum(m[1])}${m[2] === "Cr" ? " কোটি" : m[2] === "L" ? " লাখ" : " হাজার"}`],
  [/^Purchase and sale chain \((\d+)\)$/, (m) => `ক্রয় ও বিক্রয়ের ধারা (${bnNum(m[1])})`],
  [/^Paid ([\d,.]+) · Unpaid now ([\d,.]+)$/, (m) => `পরিশোধিত ${m[1]} · এখন অপরিশোধিত ${m[2]}`],
  [/^Received ([\d,.]+) · Due ([\d,.]+)$/, (m) => `প্রাপ্ত ${m[1]} · বাকি ${m[2]}`],
  [/^Remove (.+)$/, (m) => `বাদ দিন: ${BN[m[1]] ?? m[1]}`],
  [/^(pending|bank chalan done|file received|file processing|registration complete|document provided) → (pending|bank chalan done|file received|file processing|registration complete|document provided)$/, (m) => `${BN[m[1]]} → ${BN[m[2]]}`],
  [/^Mark “(.+)”$/, (m) => `“${BN[m[1]] ?? BN[m[1].replace(/\b\w/g, (c) => c.toUpperCase())] ?? m[1]}” করুন`],
  [/^Back to (.+)$/, (m) => `${BN[m[1]] ?? m[1]}-এ ফিরুন`],
  [/^(Next|Previous)$/, (m) => BN[m[1]]],
  [/^(Add|Edit|Delete|View|Create|Record|Collect|Cancel|Manage) (.+)$/, (m) => { const whole = BN[m[0]]; return whole ?? m[0]; }],
  [/^নতুন user$/, () => "নতুন ব্যবহারকারী"],
  [/^Page (\d+) of (\d+) · (\d+) records?$/, (m) => `পৃষ্ঠা ${bnNum(m[1])} / ${bnNum(m[2])} · ${bnNum(m[3])}টি তথ্য`],
  [/^(\d+) due$/i, (m) => `${bnNum(m[1])}টি বাকি`],
  [/^In (\d+) Days?$/i, (m) => `${bnNum(m[1])} দিনে`],
  [/^(\d+) Days? Over$/i, (m) => `${bnNum(m[1])} দিন পার`],
  [/^Next (\d+) days · (\d+)$/, (m) => `পরবর্তী ${bnNum(m[1])} দিন · ${bnNum(m[2])}`],
  [/^(\d+) of (\d+)$/, (m) => `${bnNum(m[1])} / ${bnNum(m[2])}`],
  [/^Per (customer|supplier)$/i, (m) => `প্রতি ${BN[m[1][0].toUpperCase() + m[1].slice(1)] ?? m[1]}`],
  [/^(\d+) vehicles bought$/, (m) => `${bnNum(m[1])}টি গাড়ি কেনা হয়েছে`],
  [/^(\d+) vehicles sold$/, (m) => `${bnNum(m[1])}টি গাড়ি বিক্রি হয়েছে`],
  [/^Installment #(\d+)$/, (m) => `কিস্তি #${bnNum(m[1])}`],
  [/^New (customer|supplier|investor|shop|user|category|permission)$/i, (m) => `নতুন ${BN[m[1][0].toUpperCase() + m[1].slice(1)] ?? m[1]}`],
  [/^Edit (customer|supplier|investor|shop|user|category|permission|vehicle)$/i, (m) => `${BN[m[1][0].toUpperCase() + m[1].slice(1)] ?? m[1]} এডিট`],
  [/^(Customer|Supplier|Investor|Vehicle|Shop|User|Category|Permission|Sale) (added|updated|deleted) successfully$/, (m) => `${BN[m[1]] ?? m[1]} ${m[2] === "added" ? "যোগ করা হয়েছে" : m[2] === "updated" ? "হালনাগাদ হয়েছে" : "মুছে ফেলা হয়েছে"}`],
  [/^Vehicle added successfully$/, () => "গাড়ি সফলভাবে যোগ করা হয়েছে"],
  [/^Sale #(\d+)$/, (m) => `বিক্রয় #${bnNum(m[1])}`],
  [/^Draft opened$/, () => "খসড়া খোলা হয়েছে"],
];

export function translateText(text: string): string | null {
  const key = text.trim();
  if (!key || !/[A-Za-z]/.test(key)) return null;
  const hit = BN[key] ?? BN[key.toLowerCase()];
  if (hit) return text.replace(key, hit);
  for (const [re, fn] of RULES) {
    const m = key.match(re);
    if (m) return text.replace(key, fn(m));
  }
  return null;
}

const skip = (n: Node) => {
  const p = n.parentElement;
  return !p || ["SCRIPT", "STYLE", "TEXTAREA", "NOSCRIPT", "SVG"].includes(p.tagName) || p.closest("[data-no-translate]") !== null;
};

function doText(node: Node) {
  if (skip(node)) return;
  const cur = node.nodeValue ?? "";
  const known = originals.get(node);
  // React rewrote the node with new English: translate that instead
  const src = known !== undefined && translateText(known) === cur ? known : cur;
  const out = translateText(src);
  if (out !== null && out !== cur) {
    originals.set(node, src);
    node.nodeValue = out;
  }
}

function doAttrs(el: Element) {
  for (const a of ATTRS) {
    const v = el.getAttribute(a);
    if (!v) continue;
    const saved = attrOriginals.get(el)?.[a];
    const src = saved !== undefined && translateText(saved) === v ? saved : v;
    const out = translateText(src);
    if (out !== null && out !== v) {
      attrOriginals.set(el, { ...(attrOriginals.get(el) ?? {}), [a]: src });
      el.setAttribute(a, out);
    }
  }
}

function walk(root: Node) {
  if (root.nodeType === Node.TEXT_NODE) return doText(root);
  if (root.nodeType !== Node.ELEMENT_NODE) return;
  const el = root as Element;
  doAttrs(el);
  const tw = document.createTreeWalker(el, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
  let n = tw.nextNode();
  while (n) {
    if (n.nodeType === Node.TEXT_NODE) doText(n);
    else doAttrs(n as Element);
    n = tw.nextNode();
  }
}

let observer: MutationObserver | null = null;

/** Translates everything on the page into Bangla and keeps translating as the page changes. */
export function startDomTranslate() {
  if (observer) return;
  walk(document.body);
  observer = new MutationObserver((muts) => {
    observer?.disconnect();
    for (const m of muts) {
      if (m.type === "characterData") doText(m.target);
      else if (m.type === "attributes") doAttrs(m.target as Element);
      else m.addedNodes.forEach((n) => walk(n));
    }
    observer?.observe(document.body, OPTS);
  });
  observer.observe(document.body, OPTS);
}

const OPTS: MutationObserverInit = { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS };

/** Back to English: puts every translated text and attribute back as it was. */
export function stopDomTranslate() {
  observer?.disconnect();
  observer = null;
  originals.forEach((src, node) => {
    if (node.isConnected) node.nodeValue = src;
  });
  originals.clear();
  attrOriginals.forEach((map, el) => {
    if (el.isConnected) for (const [a, v] of Object.entries(map)) el.setAttribute(a, v);
  });
  attrOriginals.clear();
}
