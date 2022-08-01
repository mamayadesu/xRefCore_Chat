class Typing {
    constructor() {
        this.typingUsers = {};
        this.el = document.getElementById("typing");
        this.message = document.getElementById("message");
        this.lastType = 0;
        var self = this;
        this.message.onkeydown = function(e) {
            self.imTyping();
        };
    }
    
    setTypingUser(username) {
        var self = this;
        if (typeof this.typingUsers[username] != "undefined")
        {
            clearTimeout(this.typingUsers[username]);
        }
        
        this.typingUsers[username] = setTimeout(function() {
            self.typingUsers[username] = undefined;
            self.refresh();
        }, 4000);
        self.refresh();
    }
    
    now() {
        return Math.round(new Date().getTime() / 1000);
    }
    
    unsetTypingUser(username) {
        if (typeof this.typingUsers[username] != "undefined")
        {
            clearTimeout(this.typingUsers[username]);
            this.typingUsers[username] = undefined;
        }
        this.refresh();
    }
    
    getTypingUsers() {
        var result = [];
        for (var username in this.typingUsers)
        {
            if (typeof this.typingUsers[username] != "undefined")
            {
                result.push(username);
            }
        }
        
        return result;
    }
    
    getCount() {
        var k = 0;
        for (var username in this.typingUsers)
        {
            if (typeof this.typingUsers[username] != "undefined")
            {
                k++;
            }
        }
        
        return k;
    }
    
    show(text) {
        this.el.innerHTML = text;
        this.el.style.color = "black";
    }
    
    hide() {
        this.el.innerHTML = "_";
        this.el.style.color = "white";
    }
    
    refresh() {
        var users = this.getTypingUsers();
        var count = this.getCount();
        switch (count) {
            case 0:
                this.hide();
                break;
                
            case 1:
                this.show(users[0] + " is typing...");
                break;
                
            default:
                var usersText = "";
                for (var i = 0; i < count; i++) {
                    if (i == count - 1)
                    {
                        usersText += " and " + users[i];
                    } else if (i == 0) {
                        usersText += users[i];
                    } else {
                        usersText += ", " + users[i];
                    }
                }
                usersText += " are typing...";
                this.show(usersText);
                break;
        }
    }
    
    imTyping() {
        if ((this.now() - this.lastType) < 3)
        {
            return;
        }
        this.lastType = this.now();
        var xhr = new XMLHttpRequest();
        xhr.open("POST", "/typing", true);
        var self = this;
        xhr.send();
    }
}