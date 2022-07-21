class LongPoll
{
    
    constructor(uri)
    {
        this.common_uri = uri;
        this.eventList = [];
        this.isActive = false;
        this.http = new XMLHttpRequest();
        this.enabled = true;
        this.createRequestObject(true);
    }
    
    on(event, func)
    {
        var string_event = event+"";
        this.eventList[string_event] = func;
    }
    
    getCommonUri()
    {
        return this.common_uri;
    }

    httpBuildQuery(arr)
    {
        var result = "";
        var count = 0;
        for (var key in arr)
        {
            var value = arr[key];
            count++;
            if(count == 1)
            {
                result = key+"="+encodeURIComponent(value);
            }
            else
            {
                result = result+"&"+key+"="+encodeURIComponent(value);
            }
        }
        return result;
    }

    triggerEvent(event, parameters)
    {
        if (typeof this.eventList[event] == "function")
        {
            this.eventList[event](parameters);
        }
    }
    
    createRequestObject()
    {
        if(this.isActive === false && this.enabled === true) {
            this.isActive = true;
            var parameters = {};
            var parameters_string = this.httpBuildQuery(parameters);
            
            this.http.open("POST", this.common_uri, true);
            
            this.http.timeout = 60000;
            var self = this;
            this.http.ontimeout = function()
            {
                self.triggerEvent("timeout");
                self.isActive = false;
                self.createRequestObject();
            }
            
            this.http.onreadystatechange = function()
            {
                if(this.readyState === 4)
                {
                    if(this.status === 200)
                    {
                        var response = this.responseText;
                        var string_response = response+"";
                        var responses = string_response.split("\n");
                        if (string_response.length > 0)
                        {
                            for (var key in responses)
                            {
                                var r = responses[key];
                                var data;
                                if (r.length > 0)
                                {
                                    if (typeof r != "object")
                                    {
                                        try
                                        {
                                            data = JSON.parse(r);
                                        }
                                        catch(e)
                                        {
                                            r = self.b64decode(r);
                                            data = JSON.parse(r);
                                        }
                                    }
                                    self.triggerEvent("data", data);
                                }
                            }
                        }
                        else
                        {
                            self.triggerEvent("hibernation");
                        }
                    }
                    else
                    {
                        if(self.isActive === true)
                        {
                            if (this.status == 0)
                            {
                                var data = { "connected": false, "status": false };
                            }
                            else
                            {
                                var data = { "connected": true, "status": this.status };
                            }
                            self.triggerEvent("error", data);
                        }
                    }
                    self.isActive = false;
                    self.createRequestObject();
                }
            };
            
            this.http.setRequestHeader("Content-Type","application/x-www-form-urlencoded");
            this.http.send(parameters_string);
        }
    }
    
    b64decode(data)
    {
        var b64 = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=";
        var o1, o2, o3, h1, h2, h3, h4, bits, i=0, enc="";

        do
        {  
            h1 = b64.indexOf(data.charAt(i++));
            h2 = b64.indexOf(data.charAt(i++));
            h3 = b64.indexOf(data.charAt(i++));
            h4 = b64.indexOf(data.charAt(i++));
 
            bits = h1<<18 | h2<<12 | h3<<6 | h4;
 
            o1 = bits>>16 & 0xff;
            o2 = bits>>8 & 0xff;
            o3 = bits & 0xff;
 
            if (h3 == 64) enc += String.fromCharCode(o1);
            else if (h4 == 64) enc += String.fromCharCode(o1, o2);
            else enc += String.fromCharCode(o1, o2, o3);
        } while (i < data.length);
 
        return unescape(enc);
    }

    b64encode(data)
    {    
        data = escape(data);  
        var b64 = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=";
        var o1, o2, o3, h1, h2, h3, h4, bits, i=0, enc="";
 
        do {
            o1 = data.charCodeAt(i++);
            o2 = data.charCodeAt(i++);
            o3 = data.charCodeAt(i++);
 
            bits = o1<<16 | o2<<8 | o3;
 
            h1 = bits>>18 & 0x3f;
            h2 = bits>>12 & 0x3f;
            h3 = bits>>6 & 0x3f;
            h4 = bits & 0x3f;
 
            enc += b64.charAt(h1) + b64.charAt(h2) + b64.charAt(h3) + b64.charAt(h4);
        } while (i < data.length);
 
        switch (data.length % 3) {
            case 1:
                enc = enc.slice(0, -2) + "==";
                break;
                    
            case 2:
                enc = enc.slice(0, -1) + "=";
                break;
        }
 
        return enc;
    }
    
    halt()
    {
        if (this.isActive === true) {
            this.isActive = false;
            this.triggerEvent("halted");
            this.http.abort();
            this.eventList = [];
            this.enabled = false;
        }
    }
}