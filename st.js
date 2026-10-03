(async()=>{const sleep=ms=>new Promise(r=>setTimeout(r,ms));
[...document.querySelectorAll('button')].find(b=>b.textContent.trim()==='Customer transactions').click();await sleep(800);
const i=document.querySelector('input[placeholder^="Search by name"]');i.focus();i.dispatchEvent(new Event("focusin",{bubbles:true}));await sleep(1500);
const b=[...document.querySelectorAll('ul li button')].find(x=>x.textContent.includes('Abdul Karim'));b&&b.click();await sleep(2500);return !!b})()
