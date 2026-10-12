import {defineStore} from 'pinia';
import {ref,computed} from 'vue';
import {Ability} from '@casl/ability';
import {api,setToken,onSessionExpired} from '../lib/api';
export const ability=new Ability([]);
export const useSession=defineStore('session',()=>{
 const user=ref(null);const signedIn=computed(()=>Boolean(user.value));
 function apply(u){user.value=u;ability.update(u?.abilities||[]);}
 async function login(email,password){const result=await api.post('/auth/login',{email,password});setToken(result.data.access_token);apply(result.data.user);return result.data;}
 async function logout(){try{await api.post('/auth/logout');}finally{setToken(null);apply(null);}}
 onSessionExpired(()=>{setToken(null);apply(null);window.location.assign('/login?expired=1');});
 const map={'football.view':['read','Football'],'football.manage':['manage','Football'],'administration.manage':['manage','Administration']};
 return{user,signedIn,login,logout,apply,can:(code)=>{if(!user.value)return false;const rule=map[code];return rule?ability.can(rule[0],rule[1]):false;}};
});
