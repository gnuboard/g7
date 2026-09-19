import '@testing-library/jest-dom';
import {vi} from 'vitest';
global.fetch=vi.fn();
window.scrollTo=vi.fn();
Object.defineProperty(window,'matchMedia',{writable:true,value:vi.fn().mockImplementation(query=>({matches:false,media:query,addListener:vi.fn(),removeListener:vi.fn(),addEventListener:vi.fn(),removeEventListener:vi.fn(),dispatchEvent:vi.fn()}))});
